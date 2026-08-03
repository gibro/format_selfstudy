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
$visibleactivepathoutline = \local_selfstudy\path_outline::filter_locked($baseview->outline, $showlockedactivities);

// Screen „Mein Lernstand": Begrüßung, Fortschritt über alle Kapitel, die eine
// Empfehlung und der Hinweis, dass die Reihenfolge frei ist.
// Gezaehlt werden Bausteine, also Stationen der Gliederung. Der Fortschritt aus
// base_view zaehlt nur Pflichtstationen des Pfads und meldete fuer Kurs 3
// "1 von 1", wo 14 von 36 Bausteinen erledigt waren.
$progresstotal = 0;
$progresscomplete = 0;
$seenstations = [];
foreach ($visibleactivepathoutline as $entry) {
    $entrycmid = (int)($entry->cmid ?? 0);
    if (!$entrycmid || isset($seenstations[$entrycmid])) {
        continue;
    }
    $seenstations[$entrycmid] = true;
    $progresstotal++;
    if (($entry->status ?? '') === 'complete') {
        $progresscomplete++;
    }
}
$progresspercent = $progresstotal > 0 ? (int)round($progresscomplete / $progresstotal * 100) : 0;
// Aus demselben Grund zaehlt auch "fertig" hier ueber die Bausteine.
$pathcomplete = $progresstotal > 0 && $progresscomplete >= $progresstotal;

echo html_writer::start_div('format-selfstudy-igm format-selfstudy-lernstand');

echo html_writer::start_div('format-selfstudy-lernstand-hero');
echo html_writer::start_div('');
echo html_writer::div(get_string('lernstandoverline', 'format_selfstudy'), 'format-selfstudy-igm-overline');
$firstname = trim((string)($USER->firstname ?? ''));
echo html_writer::tag('h1', $firstname !== '' ?
    get_string('lernstandgreeting', 'format_selfstudy', format_string($firstname)) :
    get_string('lernstandgreetinganon', 'format_selfstudy'));
echo html_writer::div('', 'format-selfstudy-igm-rule');
echo html_writer::tag('p', $progresstotal > 0 ?
    get_string('lernstandlead', 'format_selfstudy', (object)[
        'complete' => $progresscomplete,
        'total' => $progresstotal,
    ]) :
    get_string('lernstandleadempty', 'format_selfstudy'),
    ['class' => 'format-selfstudy-igm-lead']);
echo html_writer::end_div();
echo html_writer::div('', 'format-selfstudy-igm-triangle', ['aria-hidden' => 'true']);
echo html_writer::end_div();

echo format_selfstudy_render_progress_panel($course, $learningsummaries, $progresspercent);

echo html_writer::tag('h2', get_string('lernstandnexthead', 'format_selfstudy'),
    ['class' => 'format-selfstudy-igm-sectionhead']);
echo format_selfstudy_render_next_card($course, $visibleactivepathoutline, $pathcomplete);

echo html_writer::div(
    html_writer::tag('h2', get_string('lernstandinfotitle', 'format_selfstudy')) .
    html_writer::tag('ul',
        html_writer::tag('li', get_string('lernstandinfofree', 'format_selfstudy')) .
        html_writer::tag('li', get_string('lernstandinfotime', 'format_selfstudy')) .
        html_writer::tag('li', get_string('lernstandinfocheck', 'format_selfstudy')),
        ['class' => 'format-selfstudy-igm-infobox-list']
    ),
    'format-selfstudy-igm-infobox format-selfstudy-lernstand-infobox'
);

if (!empty($activepath)) {
    echo \local_selfstudy\output\path_renderer::render_evidence($course, (int)$activepath->id, (int)$USER->id);
}

if ($hasavailablepathchoices) {
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/local/selfstudy/personal_path.php', ['id' => $course->id]),
            get_string('learningpathsavailablebutton', 'format_selfstudy'),
            ['class' => 'format-selfstudy-igm-button format-selfstudy-igm-button-secondary']
        ),
        'format-selfstudy-lernstand-nextactions'
    );
}

if ($caneditsections) {
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/local/selfstudy/teacher_analytics.php', ['id' => $course->id]),
            get_string('teacheranalytics', 'format_selfstudy'),
            ['class' => 'format-selfstudy-igm-button format-selfstudy-igm-button-secondary']
        ) .
        html_writer::link(
            new moodle_url('/local/selfstudy/path_editor.php', ['id' => $course->id]),
            get_string('learningpatheditor', 'format_selfstudy'),
            ['class' => 'format-selfstudy-igm-button format-selfstudy-igm-button-secondary']
        ),
        'format-selfstudy-lernstand-nextactions'
    );
}

echo html_writer::end_div();

/**
 * Renders the dark progress panel with one tile per course chapter.
 *
 * @param stdClass $course
 * @param stdClass[] $summaries
 * @param int $percent
 * @return string
 */
function format_selfstudy_render_progress_panel(stdClass $course, array $summaries, int $percent): string {
    $tiles = [];
    foreach ($summaries as $summary) {
        $section = $summary->section;
        if ((int)$section->section === 0 || !$summary->cms) {
            continue;
        }

        $total = (int)$summary->completionenabled;
        $complete = (int)$summary->completioncomplete;
        $iscomplete = $total > 0 && $complete >= $total;
        $tiles[] = html_writer::link(
            new moodle_url('/course/view.php', ['id' => $course->id, 'section' => (int)$section->section]),
            html_writer::span(\local_selfstudy\output\course_renderer::get_section_title($course, $section),
                'format-selfstudy-lernstand-chaptername') .
            html_writer::span(get_string('lernstandchapterprogress', 'format_selfstudy', (object)[
                'complete' => $complete,
                'total' => $total,
            ]), 'format-selfstudy-lernstand-chapterprogress'),
            ['class' => 'format-selfstudy-lernstand-chapter' .
                ($iscomplete ? ' format-selfstudy-lernstand-chapter-complete' : '')]
        );
    }

    return html_writer::div(
        html_writer::div(
            html_writer::tag('h2', get_string('lernstandprogresstitle', 'format_selfstudy')) .
            html_writer::span($percent . ' %', 'format-selfstudy-lernstand-percent'),
            'format-selfstudy-lernstand-progresshead'
        ) .
        html_writer::div('', 'format-selfstudy-igm-bar', [
            'style' => '--igm-progress: ' . $percent . '%',
            'role' => 'progressbar',
            'aria-valuenow' => $percent,
            'aria-valuemin' => 0,
            'aria-valuemax' => 100,
        ]) .
        ($tiles ? html_writer::div(implode('', $tiles), 'format-selfstudy-lernstand-chapters') : ''),
        'format-selfstudy-lernstand-progress'
    );
}

/**
 * Renders the single recommendation: the first step that is neither done nor locked.
 *
 * @param stdClass $course
 * @param array $outline
 * @param bool $pathcomplete
 * @return string
 */
function format_selfstudy_render_next_card(stdClass $course, array $outline, bool $pathcomplete): string {
    $next = null;
    foreach ($outline as $entry) {
        if (empty($entry->cmid) || empty($entry->url)) {
            continue;
        }
        if (!in_array($entry->status ?? 'open', ['complete', 'locked', 'missing'], true)) {
            $next = $entry;
            break;
        }
    }

    // Ohne offenen Baustein sind zwei Faelle zu unterscheiden: fertig - oder
    // alles Uebrige wartet noch auf eine Voraussetzung. Das eine ist ein
    // Erfolg, das andere eine Sackgasse, und beide brauchen einen anderen Satz.
    if ($pathcomplete || !$next) {
        return html_writer::div(
            html_writer::span($pathcomplete ? '✓' : '!', 'format-selfstudy-lernstand-nexticon',
                ['aria-hidden' => 'true']) .
            html_writer::div(
                html_writer::tag('h3', get_string($pathcomplete ? 'lernstandalldone' : 'lernstandblocked',
                    'format_selfstudy')) .
                ($pathcomplete ? '' : html_writer::tag('p',
                    get_string('lernstandblockedtext', 'format_selfstudy'),
                    ['class' => 'format-selfstudy-lernstand-nexttext']) .
                    html_writer::div(
                        html_writer::link(
                            new moodle_url('/course/view.php', ['id' => $course->id, 'selfstudyview' => 'kurs']),
                            get_string('lernstandchooseother', 'format_selfstudy'),
                            ['class' => 'format-selfstudy-igm-button format-selfstudy-igm-button-secondary']
                        ),
                        'format-selfstudy-lernstand-nextactions'
                    )),
                ''
            ),
            'format-selfstudy-lernstand-next'
        );
    }

    $meta = [];
    $goal = '';
    try {
        $cm = get_fast_modinfo($course)->get_cm((int)$next->cmid);
        $meta[] = get_section_name($course, $cm->sectionnum);
        $meta[] = get_string('pluginname', 'mod_' . $cm->modname);
        $metadata = \local_selfstudy\cm_metadata::get((int)$next->cmid);
        $goal = trim((string)($metadata->learninggoal ?? ''));
        if ((int)$metadata->durationminutes > 0) {
            $meta[] = \local_selfstudy\path_stats::format_duration((int)$metadata->durationminutes);
        }
    } catch (Throwable $exception) {
        $meta = [];
    }

    return html_writer::div(
        html_writer::span('▸', 'format-selfstudy-lernstand-nexticon', ['aria-hidden' => 'true']) .
        html_writer::div(
            html_writer::div(
                html_writer::span(get_string('lernstandrecommended', 'format_selfstudy'),
                    'format-selfstudy-igm-badge') .
                ($meta ? html_writer::span(implode(' · ', $meta)) : ''),
                'format-selfstudy-lernstand-nextmeta'
            ) .
            html_writer::tag('h3', format_string($next->title ?? '')) .
            ($goal !== '' ? html_writer::tag('p', format_text($goal, FORMAT_PLAIN),
                ['class' => 'format-selfstudy-lernstand-nexttext']) : '') .
            html_writer::div(
                html_writer::link($next->url, get_string('lernstandstart', 'format_selfstudy'),
                    ['class' => 'format-selfstudy-igm-button']) .
                html_writer::link(
                    new moodle_url('/course/view.php', ['id' => $course->id, 'selfstudyview' => 'kurs']),
                    get_string('lernstandchooseother', 'format_selfstudy'),
                    ['class' => 'format-selfstudy-igm-button format-selfstudy-igm-button-secondary']
                ),
                'format-selfstudy-lernstand-nextactions'
            ),
            ''
        ),
        'format-selfstudy-lernstand-next'
    );
}
