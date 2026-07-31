<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/completionlib.php');

global $OUTPUT, $USER;

$format = course_get_format($course);
$formatoptions = $format->get_format_options();
$course = $format->get_course();
if ($PAGE->user_is_editing()) {
    course_create_sections_if_missing($course, 0);
    $renderer = $PAGE->get_renderer('format_selfstudy');
    $pathrepository = new \local_selfstudy\path_repository();
    $authoringstate = (new \local_selfstudy\authoring_workflow($pathrepository))->get_state($course);
    $authoringrenderer = new \local_selfstudy\authoring_renderer();
    echo html_writer::start_div('format-selfstudy-editing-workflow');
    echo $authoringrenderer->workflow($authoringstate);
    echo html_writer::end_div();
    echo \local_selfstudy\helper::render_pending_notice();

    if (optional_param('selfstudyactivitysettings', 0, PARAM_BOOL)) {
        $LOCAL_SELFSTUDY_PATH_EDITOR_FUNCTIONS_ONLY = true;
        require_once($CFG->dirroot . '/local/selfstudy/path_editor.php');
        $activities = local_selfstudy_path_editor_get_activities($course);
        // Normally already handled before the page header, see local_selfstudy\hook_callbacks.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            local_selfstudy_path_editor_handle_activity_settings_post($course, new moodle_url('/course/view.php', [
                'id' => $course->id,
                'selfstudyactivitysettings' => 1,
            ]));
        }

        echo html_writer::start_tag('form', [
            'method' => 'post',
            'action' => (new moodle_url('/course/view.php', [
                'id' => $course->id,
                'selfstudyactivitysettings' => 1,
            ]))->out(false),
            'class' => 'format-selfstudy-patheditor-form format-selfstudy-activitysettings-embed',
        ]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo local_selfstudy_path_editor_render_activity_settings_panel($course, $activities);
        echo html_writer::div(
            html_writer::tag('button', get_string('savechanges'), [
                'type' => 'submit',
                'name' => 'saveaction',
                'class' => 'btn btn-primary',
                'value' => 'draft',
            ]),
            'format-selfstudy-patheditor-actions'
        );
        echo html_writer::end_tag('form');
        return;
    }

    if (optional_param('selfstudypatheditor', 0, PARAM_BOOL)) {
        $LOCAL_SELFSTUDY_PATH_EDITOR_INLINE = true;
        require($CFG->dirroot . '/local/selfstudy/path_editor.php');
        return;
    }

    if (!optional_param('selfstudycontent', 0, PARAM_BOOL)) {
        return;
    }

    if (isset($displaysection) && !is_null($displaysection)) {
        $format->set_sectionnum($displaysection);
    }
    $outputclass = $format->get_output_classname('content');
    $output = new $outputclass($format);
    echo $renderer->render($output);
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

$nextcm = \local_selfstudy\learning_summary::find_next_cm($learningsummaries);
$requiredopen = \local_selfstudy\learning_summary::count_sections($learningsummaries, 'required', false);
$requiredcomplete = \local_selfstudy\learning_summary::count_sections($learningsummaries, 'required', true);
$pathrepository = new \local_selfstudy\path_repository();
$learningpaths = $pathrepository->get_paths((int)$course->id, true);
$hasavailablepathchoices = count($learningpaths) > 1 || (!empty($formatoptions['allowpersonalpaths']) &&
    \local_selfstudy\personal_path_service::has_choices($course, $learningpaths, $learningsummaries, $pathrepository));
$showpathui = !empty($formatoptions['allowpersonalpaths']) || count($learningpaths) > 1;
$baseview = \local_selfstudy\base_view::create($course, (int)$USER->id);
$activepath = $baseview->path;
$activepathprogress = $baseview->progress;
$activepathoutline = $baseview->outline;
$visibleactivepathoutline = \local_selfstudy\path_outline::filter_locked($activepathoutline, $showlockedactivities);
$pathstarted = \local_selfstudy\path_stats::has_started($activepathprogress, $visibleactivepathoutline);
$pathdurationminutes = \local_selfstudy\path_stats::sum_duration_minutes($visibleactivepathoutline);
$remainingdurationminutes = \local_selfstudy\path_stats::sum_remaining_duration_minutes($visibleactivepathoutline);
$pathstationcount = \local_selfstudy\path_stats::count_stations($visibleactivepathoutline);
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
echo html_writer::span(get_string($pathstarted ? 'courseherokickerstarted' : 'courseherokickerstart',
    'format_selfstudy'), 'format-selfstudy-coursehero-kicker');
echo html_writer::tag('h2', format_string($course->fullname), ['class' => 'format-selfstudy-coursehero-title']);
if (!empty($course->summary)) {
    echo html_writer::div(format_text($course->summary, $course->summaryformat ?? FORMAT_HTML),
        'format-selfstudy-coursehero-summary');
}

echo html_writer::start_div('format-selfstudy-actions format-selfstudy-coursehero-actions');
// Also show this before the first activity: that is exactly when learners need
// a way in. A completed path gets no primary button: the evidence block on
// this page already says everything, and "back to the overview" would loop
// onto the page the learner is standing on.
if ($continueurl && !$pathcomplete) {
    echo html_writer::link(
        $continueurl,
        $continuelabel,
        ['class' => 'btn btn-primary format-selfstudy-coursehero-primary']
    );
}
if ($hasavailablepathchoices || !empty($activepath)) {
    echo html_writer::link(
        new moodle_url('/local/selfstudy/personal_path.php', ['id' => $course->id]),
        get_string('learningpathhub', 'format_selfstudy'),
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

echo format_selfstudy_render_section_overview($course, $learningsummaries);

if (!empty($activepath)) {
    echo \local_selfstudy\output\path_renderer::render_evidence($course, (int)$activepath->id, (int)$USER->id);
}

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
 * Renders a visual overview of Moodle sections below the course hero.
 *
 * @param stdClass $course
 * @param stdClass[] $summaries
 * @return string
 */
function format_selfstudy_render_section_overview(stdClass $course, array $summaries): string {
    $cards = [];
    foreach ($summaries as $summary) {
        $section = $summary->section;
        if ((int)$section->section === 0) {
            continue;
        }

        $title = \local_selfstudy\output\course_renderer::get_section_title($course, $section);
        $imageurl = \local_selfstudy\helper::get_section_image_url($course, (int)$section->id);
        $activitycount = count(array_filter($summary->cms, static function(cm_info $cm): bool {
            return \local_selfstudy\completion_state::is_learning_activity($cm);
        }));
        $kindlabel = get_string($summary->pathkind === 'optional' ? 'optional' : 'required', 'format_selfstudy');

        $media = $imageurl ?
            html_writer::empty_tag('img', [
                'src' => $imageurl->out(false),
                'alt' => '',
                'loading' => 'lazy',
            ]) :
            html_writer::span(substr($title, 0, 1), 'format-selfstudy-sectionoverview-initial',
                ['aria-hidden' => 'true']);

        $cards[] = html_writer::tag('article',
            html_writer::div($media, 'format-selfstudy-sectionoverview-media' .
                ($imageurl ? '' : ' format-selfstudy-sectionoverview-media-fallback')) .
            html_writer::div(
                html_writer::tag('h3', $title) .
                html_writer::div(
                    html_writer::span($kindlabel, 'format-selfstudy-sectionoverview-chip') .
                    html_writer::span($summary->statuslabel, 'format-selfstudy-sectionoverview-status'),
                    'format-selfstudy-sectionoverview-meta'
                ) .
                ($summary->learninggoal !== '' ? html_writer::div(format_text($summary->learninggoal, FORMAT_PLAIN),
                    'format-selfstudy-sectionoverview-goal') : '') .
                html_writer::div(get_string('sectionoverviewactivities', 'format_selfstudy', $activitycount),
                    'format-selfstudy-sectionoverview-count'),
                'format-selfstudy-sectionoverview-body'
            ),
            ['class' => 'format-selfstudy-sectionoverview-card']
        );
    }

    if (!$cards) {
        return '';
    }

    return html_writer::tag('section',
        html_writer::div(
            html_writer::tag('h2', get_string('sectionoverview', 'format_selfstudy')) .
            html_writer::div('', 'format-selfstudy-sectionoverview-accent') .
            html_writer::div(get_string('sectionoverviewintro', 'format_selfstudy'),
                'format-selfstudy-sectionoverview-intro'),
            'format-selfstudy-sectionoverview-head'
        ) .
        html_writer::div(implode('', $cards), 'format-selfstudy-sectionoverview-grid'),
        ['class' => 'format-selfstudy-sectionoverview']
    );
}
