<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/completionlib.php');

global $OUTPUT, $USER;

$format = course_get_format($course);
$formatoptions = $format->get_format_options();
$course = $format->get_course();
if (\local_selfstudy\output\editing_page::render($course, $format, $displaysection ?? null)) {
    return;
}
$pathpointcolor = \local_selfstudy\helper::normalise_hex_color($formatoptions['pathpointcolor'] ?? '#6f1ab1');
$nextbuttoncolor = \local_selfstudy\helper::normalise_hex_color($formatoptions['nextbuttoncolor'] ?? $pathpointcolor,
    $pathpointcolor);
$showlockedactivities = !array_key_exists('showlockedactivities', $formatoptions) ||
    !empty($formatoptions['showlockedactivities']);
$modinfo = get_fast_modinfo($course);
$sections = $modinfo->get_section_info_all();
$completion = new completion_info($course);
$learningsummaries = \local_selfstudy\learning_summary::get_summaries($course, $format, $modinfo, $sections,
    $completion);
$coursecontext = context_course::instance($course->id);
$canmanagecourse = has_capability('moodle/course:update', $coursecontext);
$caneditsections = $PAGE->user_is_editing() && $canmanagecourse;

$pathrepository = new \local_selfstudy\path_repository();
$learningpaths = $pathrepository->get_paths((int)$course->id, true);
$hasavailablepathchoices = count($learningpaths) > 1 || (!empty($formatoptions['allowpersonalpaths']) &&
    \local_selfstudy\personal_path_service::has_choices($course, $learningpaths, $learningsummaries, $pathrepository));
$baseview = \local_selfstudy\base_view::create($course, (int)$USER->id);
$activepath = $baseview->path;
$activepathprogress = $baseview->progress;
$activepathoutline = $baseview->outline;
$visibleactivepathoutline = \local_selfstudy\path_outline::filter_locked($activepathoutline, $showlockedactivities);
$pathstarted = \local_selfstudy\path_stats::has_started($activepathprogress, $visibleactivepathoutline);
if (!empty($activepath)) {
    // Der Lernpfad weiss, was zur Wahl steht: Von den Wegen einer Alternative
    // und von Abschnitten, die einander ersetzen, zaehlt einer - wie im
    // Fortschritt und im Band.
    $learningsummaries = \local_selfstudy\learning_summary::apply_path_states($learningsummaries,
        \local_selfstudy\path_progress::milestone_states($course, (int)$activepath->id, (int)$USER->id));
    $band = \local_selfstudy\path_progress::band($course, (int)$activepath->id, (int)$USER->id);
    $pathdurationminutes = \local_selfstudy\path_progress::duration_minutes($course, (int)$activepath->id);
    $remainingdurationminutes = (int)($band->remainingminutes ?? 0);
    $pathstationcount = (int)$band->total;
} else {
    $pathdurationminutes = \local_selfstudy\path_stats::sum_duration_minutes($visibleactivepathoutline);
    $remainingdurationminutes = \local_selfstudy\path_stats::sum_remaining_duration_minutes($visibleactivepathoutline);
    $pathstationcount = \local_selfstudy\path_stats::count_stations($visibleactivepathoutline);
}
$nextcm = \local_selfstudy\learning_summary::find_next_cm($learningsummaries);
$requiredopen = \local_selfstudy\learning_summary::count_sections($learningsummaries, 'required', false);
$requiredcomplete = \local_selfstudy\learning_summary::count_sections($learningsummaries, 'required', true);
$heroimageurl = format_selfstudy_get_hero_image_url($course);
$coursecontacts = format_selfstudy_get_course_contacts((int)$course->id);
$continueurl = \local_selfstudy\learning_summary::get_continue_url($course, $activepathprogress, $nextcm);
$pathcomplete = !empty($activepathprogress->total) && $activepathprogress->complete >= $activepathprogress->total;
$continuelabel = get_string($pathstarted ? 'startlearning' : 'courseherostart', 'format_selfstudy');

echo html_writer::start_div('format-selfstudy-dashboard', [
    'style' => '--format-selfstudy-path-color: ' . $pathpointcolor .
        '; --format-selfstudy-next-button-color: ' . $nextbuttoncolor,
]);

echo html_writer::start_tag('section', ['class' => 'format-selfstudy-coursehero']);
echo html_writer::start_div('format-selfstudy-coursehero-main');
if ($pathcomplete) {
    $kickerkey = 'courseherokickerdone';
} else {
    $kickerkey = $pathstarted ? 'courseherokickerstarted' : 'courseherokickerstart';
}
echo html_writer::span(get_string($kickerkey, 'format_selfstudy'), 'format-selfstudy-coursehero-kicker');
echo html_writer::tag('h2', format_string($course->fullname), ['class' => 'format-selfstudy-coursehero-title']);
if (!empty($course->summary)) {
    echo html_writer::div(format_text($course->summary, $course->summaryformat ?? FORMAT_HTML),
        'format-selfstudy-coursehero-summary');
}

// A completed path says so instead of offering a way in: "continue" would send
// learners back to the page they are already standing on.
if ($pathcomplete) {
    echo html_writer::div(
        get_string('courseherocomplete', 'format_selfstudy'),
        'alert alert-success format-selfstudy-coursehero-complete'
    );
}

// Die naechste Etappe: der Abschnitt, in den "Weiterlernen" fuehrt. Kleine
// Etappen tragen weiter als die Zahl fuer den ganzen Kurs darunter.
if (!$pathcomplete && !empty($activepath)) {
    echo format_selfstudy_render_coursehero_stage($format->get_course_stage((int)$USER->id, (int)$activepath->id,
        (int)($activepathprogress->nextcmid ?? 0)));
}

echo html_writer::start_div('format-selfstudy-actions format-selfstudy-coursehero-actions');
// Also show this before the first activity: that is exactly when learners need
// a way in.
if ($continueurl && !$pathcomplete) {
    echo html_writer::link(
        $continueurl,
        $continuelabel,
        ['class' => 'btn btn-primary format-selfstudy-coursehero-primary']
    );
}
if ($hasavailablepathchoices) {
    echo html_writer::link(
        new moodle_url('/local/selfstudy/personal_path.php', ['id' => $course->id]),
        get_string('learningpathsavailablebutton', 'format_selfstudy'),
        ['class' => 'btn btn-secondary']
    );
}
if (!empty($activepath) && $pathstarted) {
    echo html_writer::link(
        new moodle_url('/local/selfstudy/evidence.php', ['id' => $course->id]),
        get_string('evidencebutton', 'local_selfstudy'),
        ['class' => 'btn btn-secondary']
    );
}
echo html_writer::end_div();
echo format_selfstudy_render_coursehero_contacts($course, $coursecontacts);
echo html_writer::end_div();

echo format_selfstudy_render_coursehero_image($heroimageurl, $course);

echo html_writer::start_div('format-selfstudy-coursehero-facts');
echo format_selfstudy_render_coursehero_fact(get_string('courseheroduration', 'format_selfstudy'),
    \local_selfstudy\path_stats::format_duration($pathdurationminutes));
echo format_selfstudy_render_coursehero_fact(get_string('courseheroremainingduration', 'format_selfstudy'),
    $pathdurationminutes > 0 && $remainingdurationminutes <= 0 ?
        get_string('courseherodurationcomplete', 'format_selfstudy') :
        \local_selfstudy\path_stats::format_duration($remainingdurationminutes));
echo format_selfstudy_render_coursehero_fact(get_string('courseherostations', 'format_selfstudy'),
    (string)$pathstationcount);
echo format_selfstudy_render_coursehero_fact(get_string('courseheroprogress', 'format_selfstudy'),
    $activepathprogress ? get_string('learningpathprogresscount', 'format_selfstudy', (object)[
        'complete' => $activepathprogress->complete,
        'total' => $activepathprogress->total,
        'percentage' => $activepathprogress->percentage,
    ]) : get_string('statusnotstarted', 'format_selfstudy'));
echo format_selfstudy_render_coursehero_fact(get_string('courseherorequired', 'format_selfstudy'),
    get_string('courseherorequiredvalue', 'format_selfstudy', (object)[
        'complete' => $requiredcomplete,
        'open' => $requiredopen,
    ]));
echo html_writer::end_div();
echo html_writer::end_tag('section');

// Nach einem Einstiegstest: welche Abschnitte sich lohnen und welche man schon kann.
echo \local_selfstudy\output\diagnosis_box::render($course, (int)$USER->id);

echo format_selfstudy_render_section_overview($course, $learningsummaries, $formatoptions);

// "Das hast du bisher gelernt" steht auf der eigenen Seite hinter dem Hero-Button
// "Mein Fortschrittsnachweis" (local/selfstudy/evidence.php). Auf der Kursseite
// wiederholte es nur, was die Kacheln und die Abschnittskarten schon sagen.

if ($caneditsections) {
    echo html_writer::start_div('format-selfstudy-actions');
    echo html_writer::link(
        new moodle_url('/local/selfstudy/teacher_analytics.php', ['id' => $course->id]),
        get_string('teacheranalytics', 'format_selfstudy'),
        ['class' => 'btn btn-secondary']
    );
    echo html_writer::link(
        new moodle_url('/local/selfstudy/path_editor.php', ['id' => $course->id]),
        get_string('learningpatheditor', 'format_selfstudy'),
        ['class' => 'btn btn-secondary']
    );
    echo html_writer::end_div();
}

echo html_writer::end_div();

/**
 * Renders the section the learner is about to continue in, with its own small bar.
 *
 * @param array|null $stage As returned by \local_selfstudy\courseformat\base::get_course_stage().
 * @return string
 */
function format_selfstudy_render_coursehero_stage(?array $stage): string {
    if (!$stage) {
        return '';
    }

    $info = '';
    foreach ([$stage['summary'], $stage['remaining']] as $text) {
        if ($text !== '') {
            $info .= html_writer::span(s($text), 'format-selfstudy-progressband-stage-info');
        }
    }
    $total = max(1, (int)$stage['total']);
    $complete = min($total, max(0, (int)$stage['complete']));
    $bar = html_writer::div(
        html_writer::span('', 'format-selfstudy-progressband-stage-fill',
            ['style' => 'width: ' . round($complete / $total * 100, 1) . '%']),
        'format-selfstudy-progressband-stage-bar',
        [
            'role' => 'progressbar',
            'aria-valuemin' => 0,
            'aria-valuemax' => $total,
            'aria-valuenow' => $complete,
            'aria-valuetext' => $stage['summary'],
        ]
    );

    return html_writer::div(
        html_writer::span(s($stage['label']), 'format-selfstudy-coursehero-stagelabel') .
        html_writer::div(
            html_writer::tag('strong', s($stage['title']), ['class' => 'format-selfstudy-progressband-stage-title']) .
            $info,
            'format-selfstudy-progressband-stage-head'
        ) .
        $bar,
        'format-selfstudy-progressband-stage format-selfstudy-coursehero-stage'
    );
}

/**
 * Renders one compact course hero fact.
 *
 * @param string $label
 * @param string $value
 * @return string
 */
function format_selfstudy_render_coursehero_fact(string $label, string $value): string {
    return html_writer::div(
        html_writer::div(s($label), 'format-selfstudy-coursehero-factlabel') .
        html_writer::div(s($value), 'format-selfstudy-coursehero-factvalue'),
        'format-selfstudy-coursehero-fact'
    );
}

/**
 * Renders the course hero image slot.
 *
 * @param moodle_url|null $imageurl
 * @param stdClass $course
 * @return string
 */
function format_selfstudy_render_coursehero_image(?moodle_url $imageurl, stdClass $course): string {
    if ($imageurl) {
        return html_writer::div(
            html_writer::empty_tag('img', [
                'src' => $imageurl->out(false),
                'alt' => '',
                'loading' => 'lazy',
            ]),
            'format-selfstudy-coursehero-image'
        );
    }

    return html_writer::div(
        html_writer::span(format_string($course->shortname ?: $course->fullname)),
        'format-selfstudy-coursehero-image format-selfstudy-coursehero-image-fallback',
        ['aria-hidden' => 'true']
    );
}

/**
 * Renders configured course contact persons in the hero.
 *
 * @param stdClass $course
 * @param stdClass[] $contacts
 * @return string
 */
function format_selfstudy_render_coursehero_contacts(stdClass $course, array $contacts): string {
    global $OUTPUT;

    if (!$contacts) {
        return '';
    }

    $rolelabels = format_selfstudy_get_course_contact_role_labels();
    $items = [];
    foreach (array_slice($contacts, 0, 10) as $contact) {
        $roles = array_values(array_filter(array_map('trim', explode(',', (string)$contact->roles))));
        $badges = [];
        foreach ($roles as $role) {
            if (isset($rolelabels[$role])) {
                $badges[] = html_writer::span($rolelabels[$role], 'format-selfstudy-coursecontact-role');
            }
        }

        $profileurl = new moodle_url('/user/view.php', ['id' => (int)$contact->userid, 'course' => (int)$course->id]);
        $picture = $OUTPUT->user_picture($contact, [
            'size' => 48,
            'courseid' => (int)$course->id,
            'link' => false,
            'class' => 'format-selfstudy-coursecontact-avatar',
        ]);

        $items[] = html_writer::link($profileurl,
            $picture .
            html_writer::span(fullname($contact), 'format-selfstudy-coursecontact-name') .
            html_writer::span(implode('', $badges), 'format-selfstudy-coursecontact-roles'),
            ['class' => 'format-selfstudy-coursecontact']
        );
    }

    return html_writer::div(
        html_writer::div(get_string('coursecontacts', 'format_selfstudy'),
            'format-selfstudy-coursecontacts-label') .
        html_writer::div(implode('', $items), 'format-selfstudy-coursecontacts-list'),
        'format-selfstudy-coursecontacts'
    );
}

/**
 * Renders the sections of the course as one list of cards.
 *
 * Es war zweimal dasselbe: Einstiegspunkte und Abschnittsuebersicht zeigten
 * dieselben Abschnitte mit denselben Zahlen und demselben Ziel. Jetzt eine Liste,
 * die beide Fragen beantwortet - was steckt drin, und wo gehe ich hinein.
 *
 * Bei freier Reihenfolge fuehrt jede Karte in ihren Abschnitt. Bei fester
 * Reihenfolge stehen alle da, fuehren aber nur bis zum ersten unfertigen
 * Abschnitt hinein: die uebrigen sind
 * ueber die Moodle-Verfuegbarkeit gesperrt, und Moodle schickt einen von dort
 * kommentarlos auf die Kursseite zurueck.
 *
 * @param stdClass $course
 * @param stdClass[] $summaries
 * @param array $formatoptions
 * @return string
 */
function format_selfstudy_render_section_overview(stdClass $course, array $summaries,
        array $formatoptions = []): string {
    $freeorder = (string)($formatoptions['modulesequence'] ?? 'free') === 'free';

    $cards = [];
    $lockrest = false;
    // Abschnitte einer Wahl oeffnen gemeinsam; dahinter geht es weiter, sobald
    // einer von ihnen fertig ist.
    $groupdone = [];
    foreach ($summaries as $summary) {
        if (!empty($summary->choicegroup)) {
            $groupdone[$summary->choicegroup] = !empty($groupdone[$summary->choicegroup]) ||
                $summary->statuskey === 'complete';
        }
    }
    $grouplocked = [];
    foreach ($summaries as $summary) {
        $section = $summary->section;
        // Ein Abschnitt ohne Aktivitaeten hat nichts zu zeigen - sonst steht hier
        // eine Karte, die nichts meldet und nirgendwohin fuehrt.
        if ((int)$section->section === 0 || !$summary->cms) {
            continue;
        }

        // Bei fester Reihenfolge stehen alle Abschnitte da, damit man den ganzen
        // Weg sieht. Begehbar ist er aber nur bis dorthin, wo die Lernenden stehen:
        // alles hinter dem ersten unfertigen Abschnitt ist gesperrt. Nach dem
        // Status allein zu gehen, ergaebe Loecher - wer irgendwann einmal etwas in
        // Abschnitt 6 abgeschlossen hat, koennte 1 und 6 betreten, 2 aber nicht.
        $group = (string)($summary->choicegroup ?? '');
        if ($group !== '') {
            $grouplocked[$group] = $grouplocked[$group] ?? (!$freeorder && $lockrest);
            $locked = $grouplocked[$group];
            if (!$freeorder && empty($groupdone[$group])) {
                $lockrest = true;
            }
        } else {
            $locked = !$freeorder && $lockrest;
            if (!$freeorder && $summary->statuskey !== 'complete') {
                $lockrest = true;
            }
        }

        $total = (int)$summary->completionenabled;
        $complete = (int)$summary->completioncomplete;
        $isdone = $total > 0 && $complete >= $total;
        $title = \local_selfstudy\output\course_renderer::get_section_title($course, $section);
        $imageurl = \local_selfstudy\helper::get_section_image_url($course, (int)$section->id);
        $kindlabel = get_string(!empty($summary->choicegroup) ? 'sectionchoice' :
            ($summary->pathkind === 'optional' ? 'optional' : 'required'), 'local_selfstudy');

        // Gezaehlt wird, was in den Fortschritt zaehlt - dieselbe Grundlage wie die
        // Kachel "Stationen" im Hero.
        $progress = $total > 0 ? get_string('coursestartmoduleprogress', 'local_selfstudy', (object)[
            'complete' => $complete,
            'total' => $total,
        ]) : '';

        $entryurl = !empty($summary->nextcm) && !empty($summary->nextcm->url) ? $summary->nextcm->url : null;
        $reachable = $entryurl && !$locked;

        $action = '';
        if ($reachable) {
            // Ein fertiger Abschnitt bleibt begehbar - wer etwas nachschlagen will,
            // kommt ueber denselben Einstieg wieder hinein. learning_summary faellt
            // dafuer auf die erste Lernaktivitaet zurueck, sobald nichts mehr offen ist.
            // Schluessel ausgeschrieben, damit eine Textsuche sie findet:
            // coursestartmodulereview, coursestartmodulecontinue, coursestartmodulebegin.
            $label = 'coursestartmodulereview';
            if (!$isdone) {
                $label = $complete > 0 ? 'coursestartmodulecontinue' : 'coursestartmodulebegin';
            }
            // Ein span, kein Link: die ganze Karte ist bereits der Link, und ein
            // <a> im <a> waere ungueltig.
            $action = html_writer::span(get_string($label, 'local_selfstudy'),
                'format-selfstudy-sectionoverview-action');
        }

        $media = $imageurl ?
            html_writer::empty_tag('img', [
                'src' => $imageurl->out(false),
                'alt' => '',
                'loading' => 'lazy',
            ]) :
            html_writer::span(substr($title, 0, 1), 'format-selfstudy-sectionoverview-initial',
                ['aria-hidden' => 'true']);

        $cardbody =
            html_writer::div($media, 'format-selfstudy-sectionoverview-media' .
                ($imageurl ? '' : ' format-selfstudy-sectionoverview-media-fallback')) .
            html_writer::div(
                html_writer::tag('h3', $title) .
                html_writer::div(
                    html_writer::span($kindlabel, 'format-selfstudy-sectionoverview-chip') .
                    html_writer::span($locked ? get_string('learningpathstatuslocked', 'local_selfstudy') :
                        $summary->statuslabel, 'format-selfstudy-sectionoverview-status'),
                    'format-selfstudy-sectionoverview-meta'
                ) .
                ($summary->learninggoal !== '' ? html_writer::div(format_text($summary->learninggoal, FORMAT_PLAIN),
                    'format-selfstudy-sectionoverview-goal') : '') .
                html_writer::div(
                    ($progress !== '' ? html_writer::span($progress,
                        'format-selfstudy-sectionoverview-count') : '') . $action,
                    'format-selfstudy-sectionoverview-foot'
                ),
                'format-selfstudy-sectionoverview-body'
            );

        $cards[] = (object)['group' => $group, 'title' => html_entity_decode($title, ENT_QUOTES, 'UTF-8'),
            'done' => $isdone, 'html' => $reachable ?
            html_writer::link($entryurl, $cardbody, [
                'class' => 'format-selfstudy-sectionoverview-card format-selfstudy-sectionoverview-card-link' .
                    ($isdone ? ' format-selfstudy-sectionoverview-card-done' : ''),
            ]) :
            html_writer::tag('article', $cardbody, ['class' => 'format-selfstudy-sectionoverview-card' .
                ($isdone ? ' format-selfstudy-sectionoverview-card-done' : '') .
                ($locked ? ' format-selfstudy-sectionoverview-card-locked' : '')])];
    }

    if (!$cards) {
        return '';
    }

    // Bei freier Reihenfolge ist die Ueberschrift eine echte Frage an die Lernenden,
    // bei fester waere sie eine leere Geste.
    $heading = $freeorder ? get_string('coursestartmodule', 'local_selfstudy')
        : get_string('sectionoverview', 'format_selfstudy');
    $intro = $freeorder ? get_string('coursestartmoduleintro', 'local_selfstudy')
        : get_string('sectionoverviewintro', 'format_selfstudy');

    return html_writer::tag('section',
        html_writer::div(
            html_writer::tag('h2', $heading) .
            html_writer::div('', 'format-selfstudy-sectionoverview-accent') .
            html_writer::div($intro, 'format-selfstudy-sectionoverview-intro'),
            'format-selfstudy-sectionoverview-head'
        ) .
        html_writer::div(format_selfstudy_render_choice_groups($cards), 'format-selfstudy-sectionoverview-grid'),
        ['class' => 'format-selfstudy-sectionoverview']
    );
}

/**
 * Puts the cards of sections that stand in for each other into one frame.
 *
 * Side by side and under one heading, the choice reads as a choice: pick one
 * of these, then it continues with the next section. Once one is done the
 * heading says which, and the others stay open for whoever wants more.
 *
 * @param stdClass[] $cards group, title, done and html of each card, in course order.
 * @return string
 */
function format_selfstudy_render_choice_groups(array $cards): string {
    $html = '';
    $first = true;
    for ($index = 0; $index < count($cards); $index++) {
        $group = (string)$cards[$index]->group;
        if ($group === '') {
            $html .= $cards[$index]->html;
            continue;
        }
        $members = [];
        while ($index < count($cards) && (string)$cards[$index]->group === $group) {
            $members[] = $cards[$index];
            $index++;
        }
        $then = $cards[$index]->title ?? '';
        $index--;

        if (count($members) < 2) {
            $html .= implode('', array_map(static fn($card): string => $card->html, $members));
            continue;
        }
        $chosen = array_values(array_filter($members, static fn($card): bool => $card->done));
        if ($chosen) {
            $text = get_string('choicegroupchosen', 'local_selfstudy', $chosen[0]->title);
        } else {
            $text = $then !== '' ? get_string('choicetextsectionsthen', 'local_selfstudy', $then) :
                get_string('choicetextsections', 'local_selfstudy');
        }
        $html .= html_writer::div(
            html_writer::div(
                html_writer::tag('h3', get_string('choicetitle', 'local_selfstudy')) .
                html_writer::tag('p', s($text)),
                'format-selfstudy-choicegroup-head'
            ) .
            html_writer::div(implode('', array_map(static fn($card): string => $card->html, $members)),
                'format-selfstudy-choicegroup-cards'),
            'format-selfstudy-choicegroup',
            // Das Fenster "Abschnitt geschafft" fuehrt hierher, wenn als Naechstes eine Wahl ansteht.
            $first ? ['id' => 'format-selfstudy-choicegroup'] : []
        );
        $first = false;
    }
    return $html;
}
