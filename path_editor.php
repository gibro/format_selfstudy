<?php
// This file is part of Moodle - http://moodle.org/

$configpaths = [__DIR__ . '/../../../config.php'];
if (!empty($_SERVER['DOCUMENT_ROOT'])) {
    $configpaths[] = rtrim($_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR) . '/config.php';
}

foreach ($configpaths as $configpath) {
    if (is_readable($configpath)) {
        require_once($configpath);
        break;
    }
}

if (empty($CFG)) {
    throw new RuntimeException('Unable to locate Moodle config.php.');
}
require_once($CFG->libdir . '/completionlib.php');

use format_selfstudy\local\path_repository;
use format_selfstudy\local\path_publish_service;
use format_selfstudy\local\path_grid_service;
use format_selfstudy\local\path_snapshot_repository;
use format_selfstudy\local\activity_filter;
use format_selfstudy\local\authoring_renderer;
use format_selfstudy\local\authoring_workflow;

if (empty($FORMAT_SELFSTUDY_PATH_EDITOR_FUNCTIONS_ONLY)) {
$courseid = required_param('id', PARAM_INT);
$pathid = optional_param('pathid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$newpath = optional_param('new', 0, PARAM_BOOL);

$course = get_course($courseid);
require_login($course);

$coursecontext = context_course::instance($course->id);
require_capability('moodle/course:update', $coursecontext);

$format = course_get_format($course);
if ($format->get_format() !== 'selfstudy') {
    throw new moodle_exception('invalidcourseformat', 'error');
}

$edit = optional_param('edit', -1, PARAM_BOOL);
if ($edit === 1 && confirm_sesskey()) {
    redirect(new moodle_url('/course/view.php', [
        'id' => $course->id,
        'section' => 1,
        'edit' => 1,
        'sesskey' => sesskey(),
    ]));
}

$repository = new path_repository();
$baseurl = new moodle_url('/course/format/selfstudy/path_editor.php', ['id' => $course->id]);
$editorsections = format_selfstudy_path_editor_get_sections($course);

$PAGE->set_url($baseurl, $pathid ? ['pathid' => $pathid] : []);
$PAGE->set_context($coursecontext);
$PAGE->set_course($course);
$PAGE->set_pagelayout('course');
$PAGE->set_title(get_string('learningpatheditor', 'format_selfstudy'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_secondary_active_tab('coursereuse');
$PAGE->requires->js_call_amd('format_selfstudy/patheditor', 'init', [[
    'syncConfirm' => get_string('learningpathsyncconfirm', 'format_selfstudy'),
    'milestoneTitle' => get_string('learningpathmilestonetitle', 'format_selfstudy'),
    'milestoneDescription' => get_string('learningpathmilestonedescription', 'format_selfstudy'),
    'milestoneSection' => get_string('learningpathmilestonesection', 'format_selfstudy'),
    'milestoneAlternatives' => get_string('learningpathmilestonealternatives', 'format_selfstudy'),
    'milestoneAlternativesChoose' => get_string('learningpathmilestonealternativeschoose', 'format_selfstudy'),
    'milestoneAlternativeFallback' => get_string('learningpathmilestone', 'format_selfstudy'),
    'milestoneRequired' => get_string('learningpathmilestonerequired', 'format_selfstudy'),
    'milestoneAlternative' => get_string('learningpathmilestonealternative', 'format_selfstudy'),
    'milestoneAlternativeWarning' => get_string('learningpathmilestonealternativewarning', 'format_selfstudy'),
    'sections' => array_values($editorsections),
    'addStep' => get_string('learningpathaddstep', 'format_selfstudy'),
    'addAlternative' => get_string('learningpathaddalternative', 'format_selfstudy'),
    'compactOff' => get_string('learningpathcompactoff', 'format_selfstudy'),
    'compactOn' => get_string('learningpathcompacton', 'format_selfstudy'),
    'delete' => get_string('delete'),
]]);

if ($pathid) {
    $requestedpath = $repository->get_path($pathid);
    if (!$requestedpath || (int)$requestedpath->courseid !== (int)$course->id) {
        throw new moodle_exception('invalidrecord', 'error');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    if ($action === 'delete' && $pathid) {
        $repository->delete_path($pathid);
        redirect($baseurl, get_string('learningpathdeleted', 'format_selfstudy'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }

    $name = required_param('name', PARAM_TEXT);
    $description = optional_param('description', '', PARAM_RAW);
    $imageurl = optional_param('imageurl', '', PARAM_URL);
    $icon = optional_param('icon', '', PARAM_ALPHANUMEXT);
    $gridjson = optional_param('gridjson', '', PARAM_RAW);
    $saveaction = optional_param('saveaction', 'draft', PARAM_ALPHA);
    $publishing = $saveaction === 'publish';

    $activities = format_selfstudy_path_editor_get_activities($course);
    if (format_selfstudy_path_editor_save_activity_settings($course, $activities)) {
        rebuild_course_cache((int)$course->id, true);
        $activities = format_selfstudy_path_editor_get_activities($course);
    }
    $publisherrors = [];
    if ($publishing) {
        $gridservice = new path_grid_service();
        $publisherrors = $gridservice->validate_grid_for_publish($gridjson, $activities, $editorsections);
        $publisherrors = array_merge($publisherrors,
            format_selfstudy_path_editor_validate_completion_rules_for_publish($activities));
    }

    $pathdata = [
        'name' => $name,
        'description' => $description,
        'descriptionformat' => FORMAT_HTML,
        'imageurl' => $imageurl,
        'icon' => $icon,
        'enabled' => 0,
    ];

    if ($pathid) {
        $repository->update_path($pathid, $pathdata);
        $message = get_string('learningpathsaved', 'format_selfstudy');
    } else {
        $pathid = $repository->create_path($course->id, $pathdata);
        $message = get_string('learningpathcreated', 'format_selfstudy');
    }

    $gridservice = $gridservice ?? new path_grid_service();
    $items = $gridservice->build_items_from_grid($gridjson, $activities, $editorsections);
    $repository->replace_path_items($pathid, $items);

    if ($publishing) {
        if ($publisherrors) {
            $message = get_string('learningpathpublishblocked', 'format_selfstudy') .
                html_writer::alist(array_map('s', $publisherrors));
            redirect(new moodle_url($baseurl, ['pathid' => $pathid]), $message, null,
                \core\output\notification::NOTIFY_WARNING);
        }

        $publisher = new path_publish_service($repository);
        $result = $publisher->publish_course_path($course, (int)$pathid);
        if (!empty($result->published)) {
            $message = get_string('learningpathpublished', 'format_selfstudy', (object)[
                'written' => $result->written,
                'skipped' => $result->skipped,
                'revision' => $result->revision,
            ]);
            redirect(new moodle_url($baseurl, ['pathid' => $pathid]), $message, null,
                \core\output\notification::NOTIFY_SUCCESS);
        }

        $message = get_string('learningpathpublishblocked', 'format_selfstudy') .
            html_writer::alist(array_map('s', $result->errors));
        redirect(new moodle_url($baseurl, ['pathid' => $pathid]), $message, null,
            \core\output\notification::NOTIFY_WARNING);
    }

    redirect(new moodle_url($baseurl, ['pathid' => $pathid]), $message, null,
        \core\output\notification::NOTIFY_SUCCESS);
}

$paths = $repository->get_paths($course->id);
if (!$pathid && !$newpath && $paths) {
    $pathid = (int)$paths[0]->id;
}

$currentpath = $pathid ? $repository->get_path_with_items($pathid) : null;
if ($pathid && !$currentpath) {
    throw new moodle_exception('invalidrecord', 'error');
}
if ($currentpath && (int)$currentpath->courseid !== (int)$course->id) {
    throw new moodle_exception('invalidcourseid', 'error');
}

$activities = format_selfstudy_path_editor_get_activities($course);
$missingcmids = format_selfstudy_path_editor_get_missing_path_cmids($currentpath, $activities);
$grid = format_selfstudy_path_editor_grid_from_path($currentpath, $activities, $editorsections);
$usedcmids = format_selfstudy_path_editor_get_grid_cmids($grid);
$activerevision = $currentpath ? (new path_snapshot_repository())->get_active_revision((int)$currentpath->id) : null;
$authoringstate = (new authoring_workflow($repository))->get_state($course, $currentpath ? (int)$currentpath->id : 0);
$authoringrenderer = new authoring_renderer();

echo $OUTPUT->header();

echo html_writer::start_div('format-selfstudy-patheditor');
echo $OUTPUT->heading(get_string('learningpatheditor', 'format_selfstudy'), 2);
echo html_writer::tag('p', get_string('learningpatheditorintro', 'format_selfstudy'),
    ['class' => 'format-selfstudy-patheditor-intro']);
echo $authoringrenderer->publish_status($authoringstate);
echo $authoringrenderer->workflow($authoringstate);
echo $authoringrenderer->issues($authoringstate);

echo html_writer::start_div('format-selfstudy-patheditor-toolbar');
echo html_writer::link(new moodle_url('/course/format/selfstudy/authoring.php', [
    'id' => $course->id,
] + ($currentpath ? ['pathid' => $currentpath->id] : [])), get_string('authoringworkflow', 'format_selfstudy'),
    ['class' => 'btn btn-secondary']);
if ($paths) {
    echo html_writer::start_tag('form', [
        'method' => 'get',
        'action' => $baseurl->out(false),
        'class' => 'format-selfstudy-patheditor-select',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $course->id]);
    echo html_writer::label(get_string('learningpathselect', 'format_selfstudy'), 'format-selfstudy-path-select');
    $options = [];
    foreach ($paths as $path) {
        $options[(int)$path->id] = format_string($path->name);
    }
    echo html_writer::select($options, 'pathid', $pathid, false, [
        'id' => 'format-selfstudy-path-select',
        'onchange' => 'this.form.submit()',
    ]);
    echo html_writer::end_tag('form');
}

echo html_writer::link(new moodle_url($baseurl, ['new' => 1]),
    get_string('learningpathnew', 'format_selfstudy'), ['class' => 'btn btn-secondary']);
echo html_writer::link(new moodle_url('/course/format/selfstudy/path_import.php', ['id' => $course->id]),
    get_string('learningpathimport', 'format_selfstudy'), ['class' => 'btn btn-secondary']);
if ($currentpath && (int)($currentpath->userid ?? 0) === 0) {
    echo html_writer::link(new moodle_url('/course/format/selfstudy/path_export.php', [
        'id' => $course->id,
        'pathid' => $currentpath->id,
        'sesskey' => sesskey(),
    ]), get_string('learningpathexport', 'format_selfstudy'), ['class' => 'btn btn-secondary']);
    echo html_writer::link(new moodle_url('/course/format/selfstudy/path_diagnosis.php', [
        'id' => $course->id,
        'pathid' => $currentpath->id,
    ]), get_string('learningpathsyncpreview', 'format_selfstudy'), ['class' => 'btn btn-secondary']);
}
echo html_writer::link(new moodle_url('/course/view.php', ['id' => $course->id]),
    get_string('viewcourse', 'format_selfstudy'), ['class' => 'btn btn-secondary']);
echo html_writer::end_div();

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => (new moodle_url($baseurl, $pathid ? ['pathid' => $pathid] : []))->out(false),
    'class' => 'format-selfstudy-patheditor-form',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
if ($missingcmids) {
    echo $OUTPUT->notification(get_string('learningpathmissingactivitywarning', 'format_selfstudy',
        implode(', ', $missingcmids)), \core\output\notification::NOTIFY_WARNING);
}

echo html_writer::start_div('format-selfstudy-patheditor-details');
echo html_writer::tag('h3', get_string('learningpath', 'format_selfstudy'));
echo format_selfstudy_path_editor_text_input('name', get_string('learningpathname', 'format_selfstudy'),
    $currentpath->name ?? '');
echo format_selfstudy_path_editor_textarea('description', get_string('learningpathdescription', 'format_selfstudy'),
    $currentpath->description ?? '');
echo format_selfstudy_path_editor_text_input('imageurl', get_string('learningpathimageurl', 'format_selfstudy'),
    $currentpath->imageurl ?? '');
echo format_selfstudy_path_editor_text_input('icon', get_string('learningpathicon', 'format_selfstudy'),
    $currentpath->icon ?? '');
$status = !empty($currentpath->enabled) && $activerevision ?
    get_string('learningpathstatuspublishedrevision', 'format_selfstudy', (object)[
        'revision' => (int)$activerevision->revision,
        'timepublished' => userdate((int)$activerevision->timepublished),
    ]) :
    get_string(!empty($currentpath->enabled) ? 'learningpathstatuspublished' : 'learningpathstatusdraft',
        'format_selfstudy');
echo html_writer::div($status, 'format-selfstudy-patheditor-publishstatus');
echo html_writer::end_div();

echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'gridjson',
    'value' => json_encode($grid),
    'data-format-selfstudy-grid-json' => '1',
]);

echo format_selfstudy_path_editor_render_activity_settings_panel($course, $activities);

echo html_writer::start_div('format-selfstudy-patheditor-gridwrap', [
    'data-format-selfstudy-grid-editor' => '1',
]);

echo html_writer::start_div('format-selfstudy-patheditor-palette');
echo html_writer::tag('h3', get_string('learningpathactivitypalette', 'format_selfstudy'));
if (!$activities) {
    echo $OUTPUT->notification(get_string('learningpathnoactivities', 'format_selfstudy'),
        \core\output\notification::NOTIFY_INFO);
} else {
    $modoptions = [];
    $sectionoptions = [];
    foreach ($activities as $activity) {
        $modoptions[(string)$activity->modname] = (string)$activity->modname;
        $sectionoptions[(int)$activity->sectionnum] = (string)$activity->sectionname;
    }
    ksort($modoptions, SORT_NATURAL | SORT_FLAG_CASE);
    ksort($sectionoptions, SORT_NUMERIC);

    echo html_writer::div(
        html_writer::label(get_string('learningpathpalettefilter', 'format_selfstudy'),
            'format-selfstudy-palette-filter') .
        html_writer::empty_tag('input', [
            'type' => 'search',
            'id' => 'format-selfstudy-palette-filter',
            'class' => 'form-control format-selfstudy-patheditor-palettefilter',
            'placeholder' => get_string('learningpathpalettefilterplaceholder', 'format_selfstudy'),
            'data-format-selfstudy-palette-filter' => '1',
        ]) .
        html_writer::label(get_string('learningpathpalettefiltertype', 'format_selfstudy'),
            'format-selfstudy-palette-filter-type') .
        html_writer::select(
            ['' => get_string('learningpathpalettefilteralltypes', 'format_selfstudy')] + $modoptions,
            'palettefiltertype',
            '',
            false,
            [
                'id' => 'format-selfstudy-palette-filter-type',
                'class' => 'form-control format-selfstudy-patheditor-palettefilterselect',
                'data-format-selfstudy-palette-filter-type' => '1',
            ]
        ) .
        html_writer::label(get_string('learningpathpalettefiltersection', 'format_selfstudy'),
            'format-selfstudy-palette-filter-section') .
        html_writer::select(
            ['' => get_string('learningpathpalettefilterallsections', 'format_selfstudy')] + $sectionoptions,
            'palettefiltersection',
            '',
            false,
            [
                'id' => 'format-selfstudy-palette-filter-section',
                'class' => 'form-control format-selfstudy-patheditor-palettefilterselect',
                'data-format-selfstudy-palette-filter-section' => '1',
            ]
        ),
        'format-selfstudy-patheditor-palettefilterwrap'
    );
    echo html_writer::start_div('format-selfstudy-patheditor-paletteitems', [
        'data-format-selfstudy-palette' => '1',
    ]);
    foreach ($activities as $activity) {
        $hidden = in_array((int)$activity->id, $usedcmids, true);
        echo html_writer::start_tag('div', [
            'class' => 'format-selfstudy-patheditor-card format-selfstudy-patheditor-palettecard',
            'draggable' => 'true',
            'role' => 'button',
            'tabindex' => '0',
            'data-format-selfstudy-palette-card' => '1',
            'data-cmid' => (int)$activity->id,
            'data-name' => $activity->name,
            'data-modname' => $activity->modname,
            'data-section' => $activity->sectionname,
            'data-sectionnum' => (int)$activity->sectionnum,
            'data-iconurl' => $activity->iconurl,
            'data-duration' => (int)$activity->durationminutes,
            'data-learninggoal' => $activity->learninggoal,
            'data-competencies' => $activity->competencies,
            'data-availability' => $activity->availabilitylabel,
            'data-completion' => $activity->completionlabel,
            'data-completionmissing' => empty($activity->hascompletion) ? '1' : '0',
            'data-editurl' => $activity->editurl,
            'data-settingslabel' => get_string('learningpatheditactivitysettings', 'format_selfstudy'),
            'data-searchtext' => format_selfstudy_path_editor_activity_search_text($activity),
            'title' => format_selfstudy_path_editor_activity_tooltip($activity),
            'hidden' => $hidden ? 'hidden' : null,
        ]);
        echo html_writer::empty_tag('img', [
            'src' => $activity->iconurl,
            'alt' => '',
            'class' => 'format-selfstudy-patheditor-cardicon',
        ]);
        echo html_writer::span($activity->name, 'format-selfstudy-patheditor-cardtitle');
        echo html_writer::span($activity->modname . ' · ' . $activity->sectionname,
            'format-selfstudy-patheditor-cardmeta');
        echo format_selfstudy_path_editor_render_card_details($activity);
        echo format_selfstudy_path_editor_render_settings_link($activity);
        echo html_writer::end_tag('div');
    }
    echo html_writer::end_div();
}
echo html_writer::end_div();

echo html_writer::start_div('format-selfstudy-patheditor-canvas');
echo html_writer::start_div('format-selfstudy-patheditor-canvasbar');
echo html_writer::tag('h3', get_string('learningpathgrid', 'format_selfstudy'));
echo html_writer::start_div('format-selfstudy-patheditor-viewcontrols', [
    'aria-label' => get_string('learningpathviewcontrols', 'format_selfstudy'),
]);
echo html_writer::tag('button', get_string('learningpathzoomout', 'format_selfstudy'), [
    'type' => 'button',
    'class' => 'btn btn-secondary btn-sm',
    'data-format-selfstudy-zoom-out' => '1',
]);
echo html_writer::tag('button', get_string('learningpathzoomreset', 'format_selfstudy'), [
    'type' => 'button',
    'class' => 'btn btn-secondary btn-sm',
    'data-format-selfstudy-zoom-reset' => '1',
]);
echo html_writer::tag('button', get_string('learningpathzoomin', 'format_selfstudy'), [
    'type' => 'button',
    'class' => 'btn btn-secondary btn-sm',
    'data-format-selfstudy-zoom-in' => '1',
]);
echo html_writer::tag('button', get_string('learningpathcompactoff', 'format_selfstudy'), [
    'type' => 'button',
    'class' => 'btn btn-secondary btn-sm',
    'aria-pressed' => 'false',
    'data-format-selfstudy-compact-toggle' => '1',
]);
echo html_writer::end_div();
echo html_writer::tag('button', get_string('learningpathaddmilestone', 'format_selfstudy'), [
    'type' => 'button',
    'class' => 'btn btn-secondary btn-sm',
    'data-format-selfstudy-add-milestone' => '1',
]);
echo html_writer::end_div();
echo html_writer::start_div('format-selfstudy-patheditor-milestonegrid', [
    'data-format-selfstudy-milestones' => '1',
]);
foreach ($grid['milestones'] as $milestoneindex => $milestone) {
    echo format_selfstudy_path_editor_render_grid_milestone($milestone, $milestoneindex, $activities,
        $grid['milestones'], $editorsections);
}
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('format-selfstudy-patheditor-actions');
echo html_writer::tag('button', get_string('learningpathsavedraft', 'format_selfstudy'), [
    'type' => 'submit',
    'name' => 'saveaction',
    'class' => 'btn btn-secondary',
    'value' => 'draft',
]);
if ($currentpath) {
    echo html_writer::link(new moodle_url('/course/format/selfstudy/path_diagnosis.php', [
        'id' => $course->id,
        'pathid' => $currentpath->id,
    ]), get_string('authoringworkflowactioncheck', 'format_selfstudy'), ['class' => 'btn btn-secondary']);
}
echo html_writer::tag('button', get_string('learningpathpublish', 'format_selfstudy'), [
    'type' => 'submit',
    'name' => 'saveaction',
    'class' => 'btn btn-primary',
    'value' => 'publish',
    'data-format-selfstudy-sync-submit' => '1',
]);
echo html_writer::end_div();
echo html_writer::end_tag('form');

if ($currentpath) {
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url($baseurl, ['pathid' => $currentpath->id, 'action' => 'delete']))->out(false),
        'class' => 'format-selfstudy-patheditor-delete',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-danger',
        'value' => get_string('delete'),
        'onclick' => "return confirm('" . get_string('delete') . "?');",
    ]);
    echo html_writer::end_tag('form');
}

if (!$paths) {
    echo $OUTPUT->notification(get_string('learningpathnone', 'format_selfstudy'),
        \core\output\notification::NOTIFY_INFO);
}

echo html_writer::end_div();
echo $OUTPUT->footer();
}

/**
 * Returns usable Moodle course sections for milestone assignment.
 *
 * @param stdClass $course
 * @return stdClass[]
 */
function format_selfstudy_path_editor_get_sections(stdClass $course): array {
    $modinfo = get_fast_modinfo($course);
    $sections = [];

    foreach ($modinfo->get_section_info_all() as $section) {
        if (empty($section->section)) {
            continue;
        }

        $sectionname = format_selfstudy_path_editor_get_section_title($course, $section);
        if ($sectionname === '') {
            $sectionname = get_string('learningpathmilestone', 'format_selfstudy') . ' ' . (int)$section->section;
        }

        $sections[(int)$section->section] = (object)[
            'sectionnum' => (int)$section->section,
            'sectionid' => (int)$section->id,
            'name' => $sectionname,
        ];
    }

    return $sections;
}

/**
 * Returns course activities that may become learning path stations.
 *
 * @param stdClass $course
 * @return stdClass[]
 */
function format_selfstudy_path_editor_get_activities(stdClass $course): array {
    $modinfo = get_fast_modinfo($course);
    $activities = [];

    foreach ($modinfo->get_section_info_all() as $section) {
        if (empty($section->section)) {
            continue;
        }
        if (empty($modinfo->sections[$section->section])) {
            continue;
        }

        $sectionname = format_selfstudy_path_editor_get_section_title($course, $section);
        if ($sectionname === '' && !empty($section->section)) {
            $sectionname = get_string('learningpathmilestone', 'format_selfstudy') . ' ' . (int)$section->section;
        }
        foreach ($modinfo->sections[$section->section] as $cmid) {
            $cm = $modinfo->get_cm($cmid);
            if (!activity_filter::is_learning_station($cm)) {
                continue;
            }

            $activities[] = (object)([
                'id' => (int)$cm->id,
                'instance' => (int)$cm->instance,
                'name' => format_string($cm->name, true),
                'modname' => $cm->modname,
                'sectionname' => $sectionname,
                'sectionnum' => (int)$section->section,
                'iconurl' => $cm->get_icon_url()->out(false),
            ] + format_selfstudy_path_editor_get_activity_metadata($course, $cm));
        }
    }

    return $activities;
}

/**
 * Returns the real Moodle section title, preferring the stored section name.
 *
 * @param stdClass $course
 * @param section_info $section
 * @return string
 */
function format_selfstudy_path_editor_get_section_title(stdClass $course, section_info $section): string {
    $title = trim((string)($section->name ?? ''));
    if ($title === '') {
        $title = trim((string)get_section_name($course, $section));
    }

    return $title;
}

/**
 * Returns stored course module ids that are no longer usable in the editor.
 *
 * @param stdClass|null $path
 * @param stdClass[] $activities
 * @return int[]
 */
function format_selfstudy_path_editor_get_missing_path_cmids(?stdClass $path, array $activities): array {
    if (!$path || empty($path->items)) {
        return [];
    }

    $activitymap = format_selfstudy_path_editor_activity_map($activities);
    $missing = [];
    foreach ($path->items as $item) {
        $cmid = (int)($item->cmid ?? 0);
        if ($cmid > 0 && empty($activitymap[$cmid])) {
            $missing[$cmid] = $cmid;
        }
    }

    ksort($missing, SORT_NUMERIC);
    return array_values($missing);
}

/**
 * Returns editor metadata for a course module.
 *
 * @param stdClass $course
 * @param cm_info $cm
 * @return array
 */
function format_selfstudy_path_editor_get_activity_metadata(stdClass $course, cm_info $cm): array {
    $metadata = format_selfstudy_get_cm_metadata((int)$cm->id);
    $competencyoptions = format_selfstudy_path_editor_get_course_competency_options($course);
    $competencyids = format_selfstudy_path_editor_get_cm_competency_ids((int)$cm->id);
    $completionrules = format_selfstudy_path_editor_get_completion_rules($course, $cm);
    $competencylabels = [];
    foreach ($competencyids as $competencyid) {
        if (isset($competencyoptions[$competencyid])) {
            $competencylabels[] = $competencyoptions[$competencyid];
        }
    }
    $availabilitylabel = get_string('learningpathavailabilityavailable', 'format_selfstudy');
    if (empty($cm->visible)) {
        $availabilitylabel = get_string('learningpathavailabilityhidden', 'format_selfstudy');
    } else if (!empty($cm->availability)) {
        $availabilitylabel = get_string('learningpathavailabilityrestricted', 'format_selfstudy');
    }

    return [
        'durationminutes' => max(0, (int)$metadata->durationminutes),
        'learninggoal' => (string)$metadata->learninggoal,
        'competencyids' => $competencyids,
        'competencies' => implode(', ', $competencylabels),
        'availabilitylabel' => $availabilitylabel,
        'hascompletion' => !empty($cm->completion),
        'completionmode' => format_selfstudy_path_editor_get_completion_mode($cm),
        'completionlabel' => format_selfstudy_path_editor_get_completion_label($cm),
        'completionrules' => $completionrules,
        'editurl' => (new moodle_url('/course/modedit.php', [
            'update' => (int)$cm->id,
            'return' => 1,
        ]))->out(false),
    ];
}

/**
 * Returns the simple completion mode exposed directly in the path editor.
 *
 * @param cm_info $cm
 * @return string
 */
function format_selfstudy_path_editor_get_completion_mode(cm_info $cm): string {
    if (empty($cm->completion)) {
        return 'none';
    }

    if ((int)$cm->completion === COMPLETION_TRACKING_MANUAL) {
        return 'manual';
    }

    if (!empty($cm->completionview)) {
        return 'view';
    }

    return 'automatic';
}

/**
 * Returns a localized label for the completion mode exposed in the editor.
 *
 * @param cm_info $cm
 * @return string
 */
function format_selfstudy_path_editor_get_completion_label(cm_info $cm): string {
    $mode = format_selfstudy_path_editor_get_completion_mode($cm);
    if ($mode === 'none') {
        return get_string('learningpathcompletionnone', 'format_selfstudy');
    }
    if ($mode === 'manual') {
        return get_string('learningpathcompletionmanual', 'format_selfstudy');
    }
    if ($mode === 'view') {
        return get_string('learningpathcompletionview', 'format_selfstudy');
    }

    return get_string('learningpathcompletioncustom', 'format_selfstudy');
}

/**
 * Returns the Moodle core completion rules used by the inline editor.
 *
 * @param stdClass $course
 * @param cm_info $cm
 * @return array
 */
function format_selfstudy_path_editor_get_completion_rules(stdClass $course, cm_info $cm): array {
    global $DB;

    $coursemodule = $DB->get_record('course_modules', ['id' => (int)$cm->id], '*', MUST_EXIST);
    $gradeitem = format_selfstudy_path_editor_get_grade_item($course, $cm);
    $custom = format_selfstudy_path_editor_get_custom_completion_rules($cm);

    $expecteddate = '';
    if (!empty($coursemodule->completionexpected)) {
        $expecteddate = userdate((int)$coursemodule->completionexpected, '%Y-%m-%d', 99, false);
    }

    return [
        'tracking' => empty($coursemodule->completion) ? 'none' :
            (((int)$coursemodule->completion === COMPLETION_TRACKING_MANUAL) ? 'manual' : 'automatic'),
        'completionview' => !empty($coursemodule->completionview),
        'completionusegrade' => $coursemodule->completiongradeitemnumber !== null,
        'completionpassgrade' => !empty($coursemodule->completionpassgrade),
        'completionexpected' => (int)($coursemodule->completionexpected ?? 0),
        'completionexpecteddate' => $expecteddate,
        'hasgradeitem' => !empty($gradeitem),
        'gradepass' => $gradeitem ? rtrim(rtrim(sprintf('%.2F', (float)$gradeitem->gradepass), '0'), '.') : '',
        'custom' => $custom,
    ];
}

/**
 * Returns the main grade item for a course module if it exists.
 *
 * @param stdClass $course
 * @param cm_info $cm
 * @return stdClass|null
 */
function format_selfstudy_path_editor_get_grade_item(stdClass $course, cm_info $cm): ?stdClass {
    global $DB;

    return $DB->get_record('grade_items', [
        'courseid' => (int)$course->id,
        'itemtype' => 'mod',
        'itemmodule' => (string)$cm->modname,
        'iteminstance' => (int)$cm->instance,
        'itemnumber' => 0,
    ], '*') ?: null;
}

/**
 * Returns supported module-specific completion fields.
 *
 * @param cm_info $cm
 * @return array
 */
function format_selfstudy_path_editor_get_custom_completion_rules(cm_info $cm): array {
    global $DB;

    if (!$DB->get_manager()->table_exists($cm->modname)) {
        return [];
    }

    $record = $DB->get_record($cm->modname, ['id' => (int)$cm->instance], '*');
    if (!$record) {
        return [];
    }

    $rules = [];
    switch ((string)$cm->modname) {
        case 'assign':
            if (property_exists($record, 'completionsubmit')) {
                $rules['completionsubmit'] = (int)$record->completionsubmit;
            }
            break;
        case 'glossary':
            if (property_exists($record, 'completionentries')) {
                $rules['completionentries'] = max(0, (int)$record->completionentries);
            }
            break;
        case 'lesson':
            if (property_exists($record, 'completionendreached')) {
                $rules['completionendreached'] = (int)$record->completionendreached;
            }
            if (property_exists($record, 'completiontimespent')) {
                $rules['completiontimespentminutes'] = (int)ceil(max(0, (int)$record->completiontimespent) / MINSECS);
            }
            break;
        case 'quiz':
            if (property_exists($record, 'completionattemptsexhausted')) {
                $rules['completionattemptsexhausted'] = (int)$record->completionattemptsexhausted;
            }
            if (property_exists($record, 'completionminattempts')) {
                $rules['completionminattempts'] = max(0, (int)$record->completionminattempts);
            }
            break;
    }

    return $rules;
}

/**
 * Converts an HTML date input value to a Moodle timestamp.
 *
 * @param string $date
 * @return int
 */
function format_selfstudy_path_editor_date_to_timestamp(string $date): int {
    $date = trim($date);
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return 0;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $date));
    if (!checkdate($month, $day, $year)) {
        return 0;
    }

    return make_timestamp($year, $month, $day, 0, 0, 0);
}

/**
 * Returns Moodle core course competencies as select options.
 *
 * @param stdClass $course
 * @return string[]
 */
function format_selfstudy_path_editor_get_course_competency_options(stdClass $course): array {
    global $DB;

    static $cache = [];

    $courseid = (int)$course->id;
    if (array_key_exists($courseid, $cache)) {
        return $cache[$courseid];
    }

    $options = [];
    try {
        if (!get_config('core_competency', 'enabled')) {
            $cache[$courseid] = [];
            return [];
        }
        foreach (\core_competency\api::list_course_competencies($course) as $coursecompetency) {
            $competency = $coursecompetency['competency'] ?? null;
            if (!$competency) {
                continue;
            }
            $id = (int)$competency->get('id');
            $shortname = format_string((string)$competency->get('shortname'), true);
            $idnumber = trim((string)$competency->get('idnumber'));
            $options[$id] = $idnumber !== '' ? $idnumber . ' - ' . $shortname : $shortname;
        }
    } catch (Throwable $exception) {
        $records = $DB->get_records_sql(
            "SELECT c.id, c.shortname, c.idnumber
               FROM {competency_coursecomp} cc
               JOIN {competency} c ON c.id = cc.competencyid
              WHERE cc.courseid = :courseid
           ORDER BY cc.sortorder ASC, c.shortname ASC",
            ['courseid' => $courseid]
        );
        foreach ($records as $record) {
            $shortname = format_string((string)$record->shortname, true);
            $idnumber = trim((string)$record->idnumber);
            $options[(int)$record->id] = $idnumber !== '' ? $idnumber . ' - ' . $shortname : $shortname;
        }
    }

    core_collator::asort($options);
    $cache[$courseid] = $options;

    return $options;
}

/**
 * Returns Moodle core competencies linked to a course module.
 *
 * @param int $cmid
 * @return int[]
 */
function format_selfstudy_path_editor_get_cm_competency_ids(int $cmid): array {
    if (!$cmid) {
        return [];
    }

    try {
        if (!get_config('core_competency', 'enabled')) {
            return [];
        }
        $competencies = \core_competency\course_module_competency::list_course_module_competencies($cmid);
    } catch (Throwable $exception) {
        return [];
    }

    $ids = [];
    foreach ($competencies as $competency) {
        $ids[] = (int)$competency->get('competencyid');
    }

    return array_values(array_unique(array_filter($ids)));
}

/**
 * Saves activity metadata and simple Moodle completion modes posted by the editor.
 *
 * @param stdClass $course
 * @param array $activities
 * @return bool
 */
function format_selfstudy_path_editor_save_activity_settings(stdClass $course, array $activities): bool {
    global $DB;

    $posted = $_POST['activitymeta'] ?? null;
    if (!is_array($posted)) {
        return false;
    }

    $activitymap = format_selfstudy_path_editor_activity_map($activities);
    $competencyoptions = format_selfstudy_path_editor_get_course_competency_options($course);
    $metadataenabled = format_selfstudy_path_editor_activity_metadata_enabled($course);
    $coursemodulecolumns = $DB->get_columns('course_modules');
    $changed = false;
    foreach ($posted as $cmid => $values) {
        $cmid = (int)$cmid;
        if (empty($activitymap[$cmid]) || !is_array($values)) {
            continue;
        }

        if ($metadataenabled) {
            format_selfstudy_save_cm_metadata($cmid, (object)[
                'learninggoal' => clean_param($values['learninggoal'] ?? '', PARAM_TEXT),
                'durationminutes' => clean_param($values['durationminutes'] ?? 0, PARAM_INT),
                'competencies' => '',
            ]);
            $changed = true;

            $submittedcompetencyids = [];
            if (isset($values['competencyids']) && is_array($values['competencyids'])) {
                $submittedcompetencyids = array_map('intval', $values['competencyids']);
            }
            $submittedcompetencyids = array_values(array_unique(array_filter($submittedcompetencyids, static function(int $id) use
                    ($competencyoptions): bool {
                return $id > 0 && isset($competencyoptions[$id]);
            })));
            $existingcompetencyids = format_selfstudy_path_editor_get_cm_competency_ids($cmid);
            foreach (array_diff($existingcompetencyids, $submittedcompetencyids) as $removedid) {
                try {
                    \core_competency\api::remove_competency_from_course_module($cmid, $removedid);
                    $changed = true;
                } catch (Throwable $exception) {
                    // Keep saving the remaining editor data even if one competency link cannot be changed.
                }
            }
            foreach (array_diff($submittedcompetencyids, $existingcompetencyids) as $addedid) {
                try {
                    \core_competency\api::add_competency_to_course_module($cmid, $addedid);
                    $changed = true;
                } catch (Throwable $exception) {
                    // Keep saving the remaining editor data even if one competency link cannot be changed.
                }
            }
        }

        $mode = clean_param($values['completiontracking'] ?? ($values['completionmode'] ?? 'none'), PARAM_ALPHA);
        if (!in_array($mode, ['none', 'manual', 'automatic'], true)) {
            continue;
        }

        $cm = $activitymap[$cmid];
        $completion = match ($mode) {
            'manual' => COMPLETION_TRACKING_MANUAL,
            'automatic' => COMPLETION_TRACKING_AUTOMATIC,
            default => COMPLETION_TRACKING_NONE,
        };
        $completionview = ($mode === 'automatic' && !empty($values['completionview'])) ? 1 : 0;
        $completionusegrade = ($mode === 'automatic' && !empty($values['completionusegrade'])) ? 1 : 0;
        $completionpassgrade = ($mode === 'automatic' && !empty($values['completionpassgrade'])) ? 1 : 0;
        if ($completionpassgrade) {
            $completionusegrade = 1;
        }
        $completionexpected = $mode === 'none' ? 0 :
            format_selfstudy_path_editor_date_to_timestamp((string)($values['completionexpecteddate'] ?? ''));

        $record = (object)[
            'id' => $cmid,
            'completion' => $completion,
        ];
        if (array_key_exists('completionview', $coursemodulecolumns)) {
            $record->completionview = $completionview;
        }
        if (array_key_exists('completiongradeitemnumber', $coursemodulecolumns)) {
            $record->completiongradeitemnumber = $completionusegrade ? 0 : null;
        }
        if (array_key_exists('completionpassgrade', $coursemodulecolumns)) {
            $record->completionpassgrade = $completionpassgrade;
        }
        if (array_key_exists('completionexpected', $coursemodulecolumns)) {
            $record->completionexpected = $completionexpected;
        }
        $DB->update_record('course_modules', $record);
        format_selfstudy_path_editor_save_grade_item_rules($course, $cm, $values, $completionpassgrade);
        format_selfstudy_path_editor_save_custom_completion_rules($cm, $values, $mode === 'automatic');
        $changed = true;
    }

    return $changed;
}

/**
 * Saves grade pass settings used by core completion pass-grade rules.
 *
 * @param stdClass $course
 * @param stdClass $activity
 * @param array $values
 * @param int $completionpassgrade
 * @return void
 */
function format_selfstudy_path_editor_save_grade_item_rules(
    stdClass $course,
    stdClass $activity,
    array $values,
    int $completionpassgrade
): void {
    global $DB;

    $gradeitem = $DB->get_record('grade_items', [
        'courseid' => (int)$course->id,
        'itemtype' => 'mod',
        'itemmodule' => (string)$activity->modname,
        'iteminstance' => (int)$activity->instance,
        'itemnumber' => 0,
    ], '*');
    if (!$gradeitem || !array_key_exists('gradepass', $values)) {
        return;
    }

    $gradepass = clean_param($values['gradepass'], PARAM_FLOAT);
    if (!$completionpassgrade && (float)$gradepass <= 0) {
        $gradepass = 0;
    }

    $DB->set_field('grade_items', 'gradepass', max(0, (float)$gradepass), ['id' => (int)$gradeitem->id]);
}

/**
 * Saves supported activity-module specific completion rules.
 *
 * @param stdClass $activity
 * @param array $values
 * @param bool $automatic
 * @return void
 */
function format_selfstudy_path_editor_save_custom_completion_rules(stdClass $activity, array $values, bool $automatic): void {
    global $DB;

    if (!$DB->get_manager()->table_exists($activity->modname)) {
        return;
    }

    $columns = $DB->get_columns($activity->modname);
    $record = (object)['id' => (int)$activity->instance];
    $changed = false;

    $setfield = static function(string $field, int $value) use (&$record, &$changed, $columns): void {
        if (!array_key_exists($field, $columns)) {
            return;
        }
        $record->{$field} = $value;
        $changed = true;
    };

    switch ((string)$activity->modname) {
        case 'assign':
            $setfield('completionsubmit', $automatic && !empty($values['completionsubmit']) ? 1 : 0);
            break;
        case 'glossary':
            $entries = $automatic ? max(0, clean_param($values['completionentries'] ?? 0, PARAM_INT)) : 0;
            $setfield('completionentries', $entries);
            break;
        case 'lesson':
            $setfield('completionendreached', $automatic && !empty($values['completionendreached']) ? 1 : 0);
            $minutes = $automatic ? max(0, clean_param($values['completiontimespentminutes'] ?? 0, PARAM_INT)) : 0;
            $setfield('completiontimespent', $minutes * MINSECS);
            break;
        case 'quiz':
            $setfield('completionattemptsexhausted',
                $automatic && !empty($values['completionattemptsexhausted']) ? 1 : 0);
            $attempts = $automatic ? max(0, clean_param($values['completionminattempts'] ?? 0, PARAM_INT)) : 0;
            $setfield('completionminattempts', $attempts);
            break;
    }

    if ($changed) {
        $DB->update_record($activity->modname, $record);
    }
}

/**
 * Validates posted completion settings that must be writable before publishing.
 *
 * @param stdClass[] $activities
 * @return string[]
 */
function format_selfstudy_path_editor_validate_completion_rules_for_publish(array $activities): array {
    $posted = $_POST['activitymeta'] ?? null;
    if (!is_array($posted)) {
        return [];
    }

    $activitymap = format_selfstudy_path_editor_activity_map($activities);
    $errors = [];
    foreach ($posted as $cmid => $values) {
        $cmid = (int)$cmid;
        if (empty($activitymap[$cmid]) || !is_array($values)) {
            continue;
        }

        $activity = $activitymap[$cmid];
        $rules = (array)($activity->completionrules ?? []);
        $custom = (array)($rules['custom'] ?? []);
        $label = (string)($activity->name ?? $cmid);
        $mode = clean_param($values['completiontracking'] ?? ($values['completionmode'] ?? 'none'), PARAM_ALPHA);
        if (!in_array($mode, ['none', 'manual', 'automatic'], true)) {
            $errors[] = get_string('learningpathvalidationcompletionrulesunsavable', 'format_selfstudy', $label);
            continue;
        }

        if ($mode !== 'automatic') {
            continue;
        }

        if ((!empty($values['completionusegrade']) || !empty($values['completionpassgrade'])) &&
                empty($rules['hasgradeitem'])) {
            $errors[] = get_string('learningpathvalidationcompletionrulesnogradeitem', 'format_selfstudy', $label);
        }

        $unsupported = false;
        switch ((string)$activity->modname) {
            case 'assign':
                $unsupported = array_key_exists('completionsubmit', $values) &&
                    !array_key_exists('completionsubmit', $custom);
                break;
            case 'glossary':
                $unsupported = array_key_exists('completionentries', $values) &&
                    !array_key_exists('completionentries', $custom);
                break;
            case 'lesson':
                $unsupported = (array_key_exists('completionendreached', $values) &&
                        !array_key_exists('completionendreached', $custom)) ||
                    (array_key_exists('completiontimespentminutes', $values) &&
                        !array_key_exists('completiontimespentminutes', $custom));
                break;
            case 'quiz':
                $unsupported = (array_key_exists('completionattemptsexhausted', $values) &&
                        !array_key_exists('completionattemptsexhausted', $custom)) ||
                    (array_key_exists('completionminattempts', $values) &&
                        !array_key_exists('completionminattempts', $custom));
                break;
        }
        if ($unsupported) {
            $errors[] = get_string('learningpathvalidationcompletionrulesunsavable', 'format_selfstudy', $label);
        }
    }

    return array_values(array_unique($errors));
}

/**
 * Renders Moodle core and supported module-specific completion fields.
 *
 * @param int $cmid
 * @param string $name
 * @param stdClass $activity
 * @return string
 */
function format_selfstudy_path_editor_render_completion_rules(int $cmid, string $name, stdClass $activity): string {
    $rules = (array)($activity->completionrules ?? []);
    $custom = (array)($rules['custom'] ?? []);
    $output = html_writer::start_div('format-selfstudy-patheditor-completionrules');
    $output .= html_writer::div(get_string('learningpathcompletionrules', 'format_selfstudy'),
        'format-selfstudy-patheditor-completionrulesheading');
    $output .= format_selfstudy_path_editor_render_checkbox($name . '[completionview]',
        'activity-completionview-' . $cmid, get_string('learningpathcompletionview', 'format_selfstudy'),
        !empty($rules['completionview']));

    if (!empty($rules['hasgradeitem'])) {
        $output .= format_selfstudy_path_editor_render_checkbox($name . '[completionusegrade]',
            'activity-completionusegrade-' . $cmid, get_string('learningpathcompletionusegrade', 'format_selfstudy'),
            !empty($rules['completionusegrade']));
        $output .= format_selfstudy_path_editor_render_checkbox($name . '[completionpassgrade]',
            'activity-completionpassgrade-' . $cmid, get_string('learningpathcompletionpassgrade', 'format_selfstudy'),
            !empty($rules['completionpassgrade']));
        $output .= html_writer::label(get_string('learningpathcompletiongradepass', 'format_selfstudy'),
            'activity-gradepass-' . $cmid);
        $output .= html_writer::empty_tag('input', [
            'type' => 'number',
            'id' => 'activity-gradepass-' . $cmid,
            'name' => $name . '[gradepass]',
            'class' => 'form-control',
            'min' => 0,
            'step' => '0.01',
            'value' => (string)($rules['gradepass'] ?? ''),
        ]);
    }

    $output .= html_writer::label(get_string('learningpathcompletionexpected', 'format_selfstudy'),
        'activity-completionexpected-' . $cmid);
    $output .= html_writer::empty_tag('input', [
        'type' => 'date',
        'id' => 'activity-completionexpected-' . $cmid,
        'name' => $name . '[completionexpecteddate]',
        'class' => 'form-control',
        'value' => (string)($rules['completionexpecteddate'] ?? ''),
    ]);

    $output .= format_selfstudy_path_editor_render_custom_completion_rules($cmid, $name, $activity, $custom);
    $output .= html_writer::end_div();

    return $output;
}

/**
 * Renders a checkbox with the hidden zero field Moodle forms normally use.
 *
 * @param string $name
 * @param string $id
 * @param string $label
 * @param bool $checked
 * @return string
 */
function format_selfstudy_path_editor_render_checkbox(string $name, string $id, string $label, bool $checked): string {
    return html_writer::div(
        html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => $name,
            'value' => 0,
        ]) .
        html_writer::empty_tag('input', [
            'type' => 'checkbox',
            'id' => $id,
            'name' => $name,
            'value' => 1,
            'checked' => $checked ? 'checked' : null,
        ]) .
        html_writer::label($label, $id),
        'format-selfstudy-patheditor-checkbox'
    );
}

/**
 * Renders supported module-specific completion rules.
 *
 * @param int $cmid
 * @param string $name
 * @param stdClass $activity
 * @param array $custom
 * @return string
 */
function format_selfstudy_path_editor_render_custom_completion_rules(
    int $cmid,
    string $name,
    stdClass $activity,
    array $custom
): string {
    $output = '';
    switch ((string)$activity->modname) {
        case 'assign':
            $output .= format_selfstudy_path_editor_render_checkbox($name . '[completionsubmit]',
                'activity-completionsubmit-' . $cmid,
                get_string('learningpathcompletionassignsubmit', 'format_selfstudy'),
                !empty($custom['completionsubmit']));
            break;
        case 'glossary':
            $output .= html_writer::label(get_string('learningpathcompletionglossaryentries', 'format_selfstudy'),
                'activity-completionentries-' . $cmid);
            $output .= html_writer::empty_tag('input', [
                'type' => 'number',
                'id' => 'activity-completionentries-' . $cmid,
                'name' => $name . '[completionentries]',
                'class' => 'form-control',
                'min' => 0,
                'step' => 1,
                'value' => max(0, (int)($custom['completionentries'] ?? 0)),
            ]);
            break;
        case 'lesson':
            $output .= format_selfstudy_path_editor_render_checkbox($name . '[completionendreached]',
                'activity-completionendreached-' . $cmid,
                get_string('learningpathcompletionlessonend', 'format_selfstudy'),
                !empty($custom['completionendreached']));
            $output .= html_writer::label(get_string('learningpathcompletionlessontime', 'format_selfstudy'),
                'activity-completiontimespent-' . $cmid);
            $output .= html_writer::empty_tag('input', [
                'type' => 'number',
                'id' => 'activity-completiontimespent-' . $cmid,
                'name' => $name . '[completiontimespentminutes]',
                'class' => 'form-control',
                'min' => 0,
                'step' => 1,
                'value' => max(0, (int)($custom['completiontimespentminutes'] ?? 0)),
            ]);
            break;
        case 'quiz':
            $output .= format_selfstudy_path_editor_render_checkbox($name . '[completionattemptsexhausted]',
                'activity-completionattemptsexhausted-' . $cmid,
                get_string('learningpathcompletionquizattemptsexhausted', 'format_selfstudy'),
                !empty($custom['completionattemptsexhausted']));
            $output .= html_writer::label(get_string('learningpathcompletionquizminattempts', 'format_selfstudy'),
                'activity-completionminattempts-' . $cmid);
            $output .= html_writer::empty_tag('input', [
                'type' => 'number',
                'id' => 'activity-completionminattempts-' . $cmid,
                'name' => $name . '[completionminattempts]',
                'class' => 'form-control',
                'min' => 0,
                'step' => 1,
                'value' => max(0, (int)($custom['completionminattempts'] ?? 0)),
            ]);
            break;
    }

    return $output;
}

/**
 * Renders the activity metadata and completion quick edit panel.
 *
 * @param array $activities
 * @return string
 */
function format_selfstudy_path_editor_render_activity_settings_panel(stdClass $course, array $activities): string {
    if (!$activities) {
        return '';
    }

    $metadataenabled = format_selfstudy_path_editor_activity_metadata_enabled($course);
    $competencyoptions = format_selfstudy_path_editor_get_course_competency_options($course);
    $completionoptions = [
        'none' => get_string('learningpathcompletionnone', 'format_selfstudy'),
        'manual' => get_string('learningpathcompletionmanual', 'format_selfstudy'),
        'automatic' => get_string('learningpathcompletionautomatic', 'format_selfstudy'),
    ];

    $output = html_writer::start_tag('details', [
        'id' => 'format-selfstudy-activitysettings',
        'class' => 'format-selfstudy-patheditor-activitysettings',
        'open' => optional_param('showactivitysettings', 0, PARAM_BOOL) ? 'open' : null,
    ]);
    $output .= html_writer::tag('summary', get_string('learningpathactivitysettings', 'format_selfstudy'));
    $output .= html_writer::tag('p', get_string($metadataenabled ? 'learningpathactivitysettingsintro' :
            'learningpathactivitysettingsintrocompletiononly', 'format_selfstudy'),
        ['class' => 'format-selfstudy-patheditor-activitysettingsintro']);
    if ($metadataenabled && !$competencyoptions) {
        $output .= html_writer::div(get_string('learningpathactivitysettingsnocompetencies', 'format_selfstudy'),
            'format-selfstudy-patheditor-activitysettingsnotice');
    }
    if (!$metadataenabled) {
        $output .= html_writer::div(get_string('learningpathactivitysettingsmetadatadisabled', 'format_selfstudy'),
            'format-selfstudy-patheditor-activitysettingsnotice');
    }
    $output .= html_writer::start_div('format-selfstudy-patheditor-activitysettingslist');

    foreach ($activities as $activity) {
        $cmid = (int)$activity->id;
        $name = 'activitymeta[' . $cmid . ']';
        $completionrules = (array)($activity->completionrules ?? []);
        $output .= html_writer::start_div('format-selfstudy-patheditor-activitysettingsitem');
        $output .= html_writer::empty_tag('img', [
            'src' => $activity->iconurl,
            'alt' => '',
            'class' => 'format-selfstudy-patheditor-cardicon',
        ]);
        $output .= html_writer::div(
            html_writer::div($activity->name, 'format-selfstudy-patheditor-activitysettingsname') .
            html_writer::div($activity->modname . ' · ' . $activity->sectionname,
                'format-selfstudy-patheditor-cardmeta'),
            'format-selfstudy-patheditor-activitysettingsheading'
        );
        if ($metadataenabled) {
            $output .= html_writer::label(get_string('activitydurationminutes', 'format_selfstudy'),
                'activity-duration-' . $cmid);
            $output .= html_writer::empty_tag('input', [
                'type' => 'number',
                'id' => 'activity-duration-' . $cmid,
                'name' => $name . '[durationminutes]',
                'class' => 'form-control',
                'min' => 0,
                'value' => max(0, (int)$activity->durationminutes),
            ]);
        }
        $output .= html_writer::label(get_string('learningpathcompletionstatus', 'format_selfstudy'),
            'activity-completion-' . $cmid);
        $output .= html_writer::select($completionoptions, $name . '[completiontracking]',
            (string)($completionrules['tracking'] ?? 'none'), false, [
                'id' => 'activity-completion-' . $cmid,
                'class' => 'form-control',
            ]);
        $output .= format_selfstudy_path_editor_render_completion_rules($cmid, $name, $activity);
        if ($metadataenabled) {
            $output .= html_writer::label(get_string('activitylearninggoal', 'format_selfstudy'), 'activity-goal-' . $cmid);
            $output .= html_writer::tag('textarea', s($activity->learninggoal), [
                'id' => 'activity-goal-' . $cmid,
                'name' => $name . '[learninggoal]',
                'class' => 'form-control',
                'rows' => 2,
            ]);
        }
        if ($metadataenabled && $competencyoptions) {
            $output .= html_writer::label(get_string('activitycompetencies', 'format_selfstudy'),
                'activity-competencies-' . $cmid);
            $output .= html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => $name . '[competencyids][]',
                'value' => 0,
            ]);
            $output .= html_writer::select($competencyoptions, $name . '[competencyids][]',
                $activity->competencyids ?? [], false, [
                    'id' => 'activity-competencies-' . $cmid,
                    'class' => 'form-control',
                    'multiple' => 'multiple',
                    'size' => min(6, max(3, count($competencyoptions))),
                ]);
        }
        $output .= format_selfstudy_path_editor_render_settings_link($activity);
        $output .= html_writer::end_div();
    }

    $output .= html_writer::end_div();
    $output .= html_writer::end_tag('details');

    return $output;
}

/**
 * Returns whether optional activity metadata is enabled for this course.
 *
 * @param stdClass $course
 * @return bool
 */
function format_selfstudy_path_editor_activity_metadata_enabled(stdClass $course): bool {
    try {
        $options = course_get_format($course)->get_format_options();
    } catch (Throwable $exception) {
        return true;
    }

    return !array_key_exists('useactivitymetadata', $options) || !empty($options['useactivitymetadata']);
}

/**
 * Converts stored path items into the grid model used by the editor.
 *
 * @param stdClass|null $path
 * @param stdClass[] $activities
 * @param stdClass[] $sections
 * @return array
 */
function format_selfstudy_path_editor_grid_from_path(?stdClass $path, array $activities, array $sections = []): array {
    $activitymap = format_selfstudy_path_editor_activity_map($activities);
    $grid = ['milestones' => []];
    $current = null;

    if (!$path || empty($path->items)) {
        return format_selfstudy_path_editor_default_grid($activities);
    }

    $children = [];
    foreach ($path->items as $item) {
        if (!empty($item->parentid)) {
            $children[(int)$item->parentid][] = $item;
        }
    }

    $rootitems = array_values(array_filter($path->items, static function(stdClass $item): bool {
        return empty($item->parentid);
    }));
    usort($rootitems, static function(stdClass $left, stdClass $right): int {
        return ((int)$left->sortorder <=> (int)$right->sortorder) ?: ((int)$left->id <=> (int)$right->id);
    });

    $ensurecurrent = static function() use (&$grid, &$current): void {
        if ($current !== null) {
            return;
        }
        $grid['milestones'][] = [
            'key' => 'orphan',
            'title' => get_string('learningpathmilestone', 'format_selfstudy'),
            'description' => '',
            'sectionnum' => 0,
            'alternativegroup' => '',
            'alternativepeers' => [],
            'rows' => [],
        ];
        $current = count($grid['milestones']) - 1;
    };

    foreach ($rootitems as $item) {
        if ($item->itemtype === path_repository::ITEM_MILESTONE || $item->itemtype === path_repository::ITEM_SEQUENCE) {
            $config = format_selfstudy_path_editor_decode_configdata((string)($item->configdata ?? ''));
            $milestone = [
                'key' => (string)($config['milestonekey'] ?? ('item-' . (int)$item->id)),
                'title' => trim((string)($item->title ?? '')),
                'description' => (string)($item->description ?? ''),
                'sectionnum' => (int)($config['sectionnum'] ?? 0),
                'alternativegroup' => (string)($config['alternativegroup'] ?? ''),
                'alternativepeers' => format_selfstudy_path_editor_clean_string_array($config['alternativepeers'] ?? []),
                'rows' => [],
            ];

            foreach ($children[(int)$item->id] ?? [] as $child) {
                $row = format_selfstudy_path_editor_grid_row_from_item($child, $children, $activitymap);
                if ($row) {
                    $milestone['rows'][] = $row;
                }
            }
            if (empty($milestone['sectionnum'])) {
                $milestone['sectionnum'] = format_selfstudy_path_editor_get_section_num_from_rows(
                    $milestone['rows'],
                    $activitymap
                );
            }
            $sectiontitle = format_selfstudy_path_editor_get_section_title_by_num(
                (int)$milestone['sectionnum'],
                $sections
            );
            if ($sectiontitle === '') {
                $sectiontitle = format_selfstudy_path_editor_get_section_title_from_rows(
                    $milestone['rows'],
                    $activitymap
                );
            }
            $milestone['title'] = $sectiontitle !== '' ? $sectiontitle :
                get_string('learningpathmilestone', 'format_selfstudy');

            $grid['milestones'][] = $milestone;
            $current = count($grid['milestones']) - 1;
            continue;
        }

        $row = format_selfstudy_path_editor_grid_row_from_item($item, $children, $activitymap);
        if (!$row) {
            continue;
        }
        $ensurecurrent();
        $grid['milestones'][$current]['rows'][] = $row;
    }

    if (!$grid['milestones']) {
        return format_selfstudy_path_editor_default_grid($activities);
    }

    return format_selfstudy_path_editor_normalise_milestone_alternatives($grid);
}

/**
 * Returns a default empty grid with one milestone per usable course section.
 *
 * @param stdClass[] $activities
 * @return array
 */
function format_selfstudy_path_editor_default_grid(array $activities = []): array {
    $sections = [];
    foreach ($activities as $activity) {
        $sectionnum = (int)($activity->sectionnum ?? 0);
        if ($sectionnum <= 0 || isset($sections[$sectionnum])) {
            continue;
        }
        $sections[$sectionnum] = (string)($activity->sectionname ?? '');
    }

    if (!$sections) {
        return [
            'milestones' => [[
                'title' => get_string('learningpathmilestone', 'format_selfstudy'),
                'description' => '',
                'key' => 'milestone-1',
                'sectionnum' => 0,
                'alternativegroup' => '',
                'alternativepeers' => [],
                'rows' => [[]],
            ]],
        ];
    }

    ksort($sections);
    $milestones = [];
    foreach ($sections as $sectionnum => $sectionname) {
        $milestones[] = [
            'key' => 'section-' . (int)$sectionnum,
            'title' => $sectionname !== '' ? $sectionname : get_string('learningpathmilestone', 'format_selfstudy'),
            'description' => '',
            'sectionnum' => (int)$sectionnum,
            'alternativegroup' => '',
            'alternativepeers' => [],
            'rows' => [[]],
        ];
    }

    return ['milestones' => $milestones];
}

/**
 * Decodes a path item's configdata JSON.
 *
 * @param string $configdata
 * @return array
 */
function format_selfstudy_path_editor_decode_configdata(string $configdata): array {
    if (trim($configdata) === '') {
        return [];
    }

    $decoded = json_decode($configdata, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Cleans an array of string identifiers.
 *
 * @param mixed $values
 * @return string[]
 */
function format_selfstudy_path_editor_clean_string_array($values): array {
    if (!is_array($values)) {
        return [];
    }

    $cleaned = [];
    foreach ($values as $value) {
        $value = clean_param((string)$value, PARAM_ALPHANUMEXT);
        if ($value !== '') {
            $cleaned[] = $value;
        }
    }

    return array_values(array_unique($cleaned));
}

/**
 * Converts legacy alternative groups into peer selections and keeps selections symmetric.
 *
 * @param array $grid
 * @return array
 */
function format_selfstudy_path_editor_normalise_milestone_alternatives(array $grid): array {
    $keys = [];
    $groups = [];
    foreach ($grid['milestones'] as $index => &$milestone) {
        $key = clean_param((string)($milestone['key'] ?? ''), PARAM_ALPHANUMEXT);
        if ($key === '' || isset($keys[$key])) {
            $key = 'milestone-' . ((int)$index + 1);
        }
        $milestone['key'] = $key;
        $keys[$key] = true;

        $group = trim((string)($milestone['alternativegroup'] ?? ''));
        if ($group !== '') {
            $groups[$group][] = $key;
        }
        $milestone['alternativepeers'] = format_selfstudy_path_editor_clean_string_array(
            $milestone['alternativepeers'] ?? []
        );
    }
    unset($milestone);

    foreach ($groups as $groupkeys) {
        if (count($groupkeys) < 2) {
            continue;
        }
        foreach ($grid['milestones'] as &$milestone) {
            if (!in_array($milestone['key'], $groupkeys, true)) {
                continue;
            }
            $milestone['alternativepeers'] = array_values(array_unique(array_merge(
                $milestone['alternativepeers'],
                array_values(array_diff($groupkeys, [$milestone['key']]))
            )));
        }
        unset($milestone);
    }

    $validkeys = array_keys($keys);
    foreach ($grid['milestones'] as &$milestone) {
        $milestone['alternativepeers'] = array_values(array_filter($milestone['alternativepeers'],
            static function(string $peerkey) use ($validkeys, $milestone): bool {
                return $peerkey !== $milestone['key'] && in_array($peerkey, $validkeys, true);
            }));
    }
    unset($milestone);

    foreach ($grid['milestones'] as $milestone) {
        foreach ($milestone['alternativepeers'] as $peerkey) {
            foreach ($grid['milestones'] as &$candidate) {
                if ($candidate['key'] !== $peerkey) {
                    continue;
                }
                $candidate['alternativepeers'][] = $milestone['key'];
                $candidate['alternativepeers'] = array_values(array_unique($candidate['alternativepeers']));
            }
            unset($candidate);
        }
    }

    return $grid;
}

/**
 * Converts one stored item into one grid row.
 *
 * @param stdClass $item
 * @param array $children
 * @param stdClass[] $activitymap
 * @return array
 */
function format_selfstudy_path_editor_grid_row_from_item(stdClass $item, array $children, array $activitymap): array {
    if ($item->itemtype === path_repository::ITEM_STATION && !empty($item->cmid) &&
            !empty($activitymap[(int)$item->cmid])) {
        return [['cmid' => (int)$item->cmid]];
    }

    if ($item->itemtype !== path_repository::ITEM_ALTERNATIVE) {
        return [];
    }

    $row = [];
    foreach ($children[(int)$item->id] ?? [] as $child) {
        if ($child->itemtype === path_repository::ITEM_STATION && !empty($child->cmid) &&
                !empty($activitymap[(int)$child->cmid])) {
            $row[] = ['cmid' => (int)$child->cmid];
        }
    }

    return $row;
}

/**
 * Returns the Moodle section title for the first activity found in milestone rows.
 *
 * @param array $rows
 * @param stdClass[] $activitymap
 * @return string
 */
function format_selfstudy_path_editor_get_section_title_from_rows(array $rows, array $activitymap): string {
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach ($row as $cell) {
            $cmid = (int)($cell['cmid'] ?? 0);
            if ($cmid && !empty($activitymap[$cmid]->sectionname)) {
                return (string)$activitymap[$cmid]->sectionname;
            }
        }
    }

    return '';
}

/**
 * Returns the Moodle section number for the first activity found in milestone rows.
 *
 * @param array $rows
 * @param stdClass[] $activitymap
 * @return int
 */
function format_selfstudy_path_editor_get_section_num_from_rows(array $rows, array $activitymap): int {
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach ($row as $cell) {
            $cmid = (int)($cell['cmid'] ?? 0);
            if ($cmid && !empty($activitymap[$cmid]->sectionnum)) {
                return (int)$activitymap[$cmid]->sectionnum;
            }
        }
    }

    return 0;
}

/**
 * Returns the Moodle section title for the first activity found in cleaned submitted rows.
 *
 * @param array $rows
 * @param stdClass[] $activitymap
 * @return string
 */
function format_selfstudy_path_editor_get_section_title_from_clean_rows(array $rows, array $activitymap): string {
    foreach ($rows as $row) {
        foreach ($row as $cmid) {
            $cmid = (int)$cmid;
            if ($cmid && !empty($activitymap[$cmid]->sectionname)) {
                return (string)$activitymap[$cmid]->sectionname;
            }
        }
    }

    return '';
}

/**
 * Returns a Moodle section title by section number.
 *
 * @param int $sectionnum
 * @param stdClass[] $sections
 * @return string
 */
function format_selfstudy_path_editor_get_section_title_by_num(int $sectionnum, array $sections): string {
    if ($sectionnum <= 0 || empty($sections[$sectionnum])) {
        return '';
    }

    return (string)($sections[$sectionnum]->name ?? '');
}

/**
 * Builds path items from submitted grid JSON.
 *
 * @param string $gridjson
 * @param stdClass[] $activities
 * @param stdClass[] $sections
 * @return array
 */
function format_selfstudy_path_editor_build_items_from_grid(string $gridjson, array $activities,
        array $sections = []): array {
    $service = new path_grid_service();
    return $service->build_items_from_grid($gridjson, $activities, $sections);
}

/**
 * Validates submitted grid JSON before publishing.
 *
 * @param string $gridjson
 * @param stdClass[] $activities
 * @param stdClass[] $sections
 * @return string[]
 */
function format_selfstudy_path_editor_validate_grid_for_publish(string $gridjson, array $activities,
        array $sections = []): array {
    $service = new path_grid_service();
    return $service->validate_grid_for_publish($gridjson, $activities, $sections);
}

/**
 * Returns a readable milestone label for validation messages.
 *
 * @param array $milestone
 * @param int $milestoneindex
 * @param stdClass[] $sections
 * @return string
 */
function format_selfstudy_path_editor_get_validation_milestone_label(array $milestone, int $milestoneindex,
        array $sections): string {
    $title = format_selfstudy_path_editor_get_section_title_by_num((int)($milestone['sectionnum'] ?? 0), $sections);
    if ($title === '') {
        $title = trim((string)($milestone['title'] ?? ''));
    }

    return $title !== '' ? $title :
        get_string('learningpathmilestone', 'format_selfstudy') . ' ' . ((int)$milestoneindex + 1);
}

/**
 * Validates milestone alternative relationships.
 *
 * @param array $grid
 * @return string[]
 */
function format_selfstudy_path_editor_validate_alternative_groups(array $grid): array {
    $milestones = is_array($grid['milestones'] ?? null) ? $grid['milestones'] : [];
    $bykey = [];
    foreach ($milestones as $index => $milestone) {
        if (!is_array($milestone)) {
            continue;
        }
        $key = clean_param((string)($milestone['key'] ?? ''), PARAM_ALPHANUMEXT);
        if ($key === '') {
            $key = 'milestone-' . ((int)$index + 1);
        }
        $bykey[$key] = format_selfstudy_path_editor_clean_string_array($milestone['alternativepeers'] ?? []);
    }

    $errors = [];
    foreach ($bykey as $key => $peers) {
        foreach ($peers as $peerkey) {
            if (empty($bykey[$peerkey]) || !in_array($key, $bykey[$peerkey], true)) {
                $errors[] = get_string('learningpathvalidationalternativesinvalid', 'format_selfstudy');
            }
        }
    }

    return $errors;
}

/**
 * Returns all course module ids used in the grid.
 *
 * @param array $grid
 * @return int[]
 */
function format_selfstudy_path_editor_get_grid_cmids(array $grid): array {
    $cmids = [];
    foreach (($grid['milestones'] ?? []) as $milestone) {
        foreach (($milestone['rows'] ?? []) as $row) {
            foreach ($row as $cell) {
                $cmid = (int)($cell['cmid'] ?? 0);
                if ($cmid) {
                    $cmids[] = $cmid;
                }
            }
        }
    }

    return array_values(array_unique($cmids));
}

/**
 * Renders one editable milestone container.
 *
 * @param array $milestone
 * @param int $milestoneindex
 * @param stdClass[] $activities
 * @return string
 */
function format_selfstudy_path_editor_render_grid_milestone(array $milestone, int $milestoneindex,
        array $activities, array $milestones, array $sections): string {
    $activitymap = format_selfstudy_path_editor_activity_map($activities);
    $key = clean_param((string)($milestone['key'] ?? ('milestone-' . ((int)$milestoneindex + 1))), PARAM_ALPHANUMEXT);
    $title = trim((string)($milestone['title'] ?? ''));
    $description = (string)($milestone['description'] ?? '');
    $sectionnum = (int)($milestone['sectionnum'] ?? 0);
    $rows = is_array($milestone['rows'] ?? null) ? $milestone['rows'] : [];
    if (!$rows) {
        $rows = [[]];
    }
    $sectiontitle = format_selfstudy_path_editor_get_section_title_by_num($sectionnum, $sections);
    if ($sectiontitle === '') {
        $sectiontitle = format_selfstudy_path_editor_get_section_title_from_rows($rows, $activitymap);
    }
    if ($sectiontitle !== '') {
        $title = $sectiontitle;
    }
    $alternativepeers = format_selfstudy_path_editor_clean_string_array($milestone['alternativepeers'] ?? []);

    $output = html_writer::start_div('format-selfstudy-patheditor-milestone', [
        'data-format-selfstudy-milestone' => '1',
        'data-format-selfstudy-milestone-key' => $key,
    ]);
    $output .= html_writer::start_div('format-selfstudy-patheditor-milestonehead');
    $output .= html_writer::span('↕', 'format-selfstudy-patheditor-draghandle', [
        'draggable' => 'true',
        'data-format-selfstudy-milestone-draghandle' => '1',
        'title' => get_string('learningpathsortdrag', 'format_selfstudy'),
    ]);
    $output .= html_writer::empty_tag('input', [
        'type' => 'text',
        'class' => 'form-control format-selfstudy-patheditor-milestonetitle',
        'value' => $title,
        'placeholder' => get_string('learningpathmilestonetitle', 'format_selfstudy'),
        'data-format-selfstudy-milestone-title' => '1',
        'readonly' => 'readonly',
    ]);
    $output .= html_writer::span('', 'format-selfstudy-patheditor-milestonestatus', [
        'data-format-selfstudy-milestone-status' => '1',
    ]);
    $output .= html_writer::tag('button', get_string('delete'), [
        'type' => 'button',
        'class' => 'btn btn-secondary btn-sm',
        'data-format-selfstudy-remove-milestone' => '1',
    ]);
    $output .= html_writer::end_div();
    $sectionoptions = [0 => get_string('choosedots')];
    foreach ($sections as $section) {
        $sectionoptions[(int)$section->sectionnum] = $section->name;
    }
    $output .= html_writer::div(
        html_writer::label(get_string('learningpathmilestonesection', 'format_selfstudy'),
            'format-selfstudy-milestone-section-' . $milestoneindex) .
        html_writer::select($sectionoptions, '', $sectionnum, false, [
            'class' => 'form-control format-selfstudy-patheditor-milestonesection',
            'id' => 'format-selfstudy-milestone-section-' . $milestoneindex,
            'data-format-selfstudy-milestone-section' => '1',
        ]),
        'format-selfstudy-patheditor-milestonesectionwrap'
    );
    $options = '';
    foreach ($milestones as $optionindex => $optionmilestone) {
        $optionkey = clean_param((string)($optionmilestone['key'] ?? ('milestone-' . ((int)$optionindex + 1))),
            PARAM_ALPHANUMEXT);
        if ($optionkey === '' || $optionkey === $key) {
            continue;
        }
        $optiontitle = trim((string)($optionmilestone['title'] ?? ''));
        if ($optiontitle === '') {
            $optiontitle = get_string('learningpathmilestone', 'format_selfstudy') . ' ' . ((int)$optionindex + 1);
        }
        $optionid = 'format-selfstudy-milestone-alternative-' . $milestoneindex . '-' . $optionkey;
        $options .= html_writer::label(
            html_writer::empty_tag('input', [
                'type' => 'checkbox',
                'value' => $optionkey,
                'checked' => in_array($optionkey, $alternativepeers, true) ? 'checked' : null,
                'data-format-selfstudy-milestone-alternative-option' => '1',
                'id' => $optionid,
            ]) .
            html_writer::span($optiontitle),
            $optionid,
            false,
            ['class' => 'format-selfstudy-patheditor-dropdownoption']
        );
    }
    $output .= html_writer::div(
        html_writer::label(get_string('learningpathmilestonealternatives', 'format_selfstudy'),
            'format-selfstudy-milestone-alternatives-' . $milestoneindex) .
        html_writer::tag('details',
            html_writer::tag('summary', get_string('learningpathmilestonealternativeschoose', 'format_selfstudy'),
                ['class' => 'format-selfstudy-patheditor-dropdownsummary']) .
            html_writer::div($options, 'format-selfstudy-patheditor-dropdownoptions'),
            [
            'class' => 'format-selfstudy-patheditor-milestonealternatives',
            'id' => 'format-selfstudy-milestone-alternatives-' . $milestoneindex,
            'data-format-selfstudy-milestone-alternatives' => '1',
            ]
        ),
        'format-selfstudy-patheditor-milestonealt'
    );
    $output .= html_writer::div('', 'format-selfstudy-patheditor-milestonewarning', [
        'data-format-selfstudy-milestone-warning' => '1',
        'hidden' => 'hidden',
    ]);
    $output .= html_writer::tag('textarea', s($description), [
        'class' => 'form-control format-selfstudy-patheditor-milestonedescription',
        'rows' => 2,
        'placeholder' => get_string('learningpathmilestonedescription', 'format_selfstudy'),
        'data-format-selfstudy-milestone-description' => '1',
    ]);
    $output .= html_writer::start_div('format-selfstudy-patheditor-rows', [
        'data-format-selfstudy-rows' => '1',
    ]);

    foreach ($rows as $row) {
        $output .= html_writer::start_div('format-selfstudy-patheditor-row', [
            'data-format-selfstudy-row' => '1',
            'draggable' => 'true',
        ]);
        if (is_array($row)) {
            foreach ($row as $cell) {
                $cmid = (int)($cell['cmid'] ?? 0);
                if (!empty($activitymap[$cmid])) {
                    $output .= format_selfstudy_path_editor_render_grid_card($activitymap[$cmid]);
                }
            }
        }
        $output .= html_writer::tag('button', get_string('learningpathaddalternative', 'format_selfstudy'), [
            'type' => 'button',
            'class' => 'btn btn-secondary btn-sm format-selfstudy-patheditor-addcell',
            'data-format-selfstudy-add-cell' => '1',
        ]);
        $output .= html_writer::end_div();
    }

    $output .= html_writer::end_div();
    $output .= html_writer::tag('button', get_string('learningpathaddstep', 'format_selfstudy'), [
        'type' => 'button',
        'class' => 'btn btn-secondary btn-sm',
        'data-format-selfstudy-add-row' => '1',
    ]);
    $output .= html_writer::end_div();

    return $output;
}

/**
 * Renders one activity card inside the grid.
 *
 * @param stdClass $activity
 * @return string
 */
function format_selfstudy_path_editor_render_grid_card(stdClass $activity): string {
    $output = html_writer::start_div('format-selfstudy-patheditor-card format-selfstudy-patheditor-gridcard', [
        'data-format-selfstudy-grid-card' => '1',
        'data-cmid' => (int)$activity->id,
        'data-name' => $activity->name,
        'data-modname' => $activity->modname,
        'data-section' => $activity->sectionname,
        'data-iconurl' => $activity->iconurl,
        'data-duration' => (int)$activity->durationminutes,
        'data-learninggoal' => $activity->learninggoal,
        'data-competencies' => $activity->competencies,
        'data-availability' => $activity->availabilitylabel,
        'data-completion' => $activity->completionlabel,
        'data-completionmissing' => empty($activity->hascompletion) ? '1' : '0',
        'data-editurl' => $activity->editurl,
        'data-settingslabel' => get_string('learningpatheditactivitysettings', 'format_selfstudy'),
        'title' => format_selfstudy_path_editor_activity_tooltip($activity),
        'draggable' => 'true',
    ]);
    $output .= html_writer::empty_tag('img', [
        'src' => $activity->iconurl,
        'alt' => '',
        'class' => 'format-selfstudy-patheditor-cardicon',
    ]);
    $output .= html_writer::span($activity->name, 'format-selfstudy-patheditor-cardtitle');
    $output .= html_writer::span($activity->modname . ' · ' . $activity->sectionname,
        'format-selfstudy-patheditor-cardmeta');
    $output .= format_selfstudy_path_editor_render_card_details($activity);
    $output .= format_selfstudy_path_editor_render_settings_link($activity);
    $output .= html_writer::tag('button', get_string('delete'), [
        'type' => 'button',
        'class' => 'btn btn-link btn-sm format-selfstudy-patheditor-removecard',
        'data-format-selfstudy-remove-card' => '1',
    ]);
    $output .= html_writer::end_div();

    return $output;
}

/**
 * Renders compact metadata chips for an editor activity card.
 *
 * @param stdClass $activity
 * @return string
 */
function format_selfstudy_path_editor_render_card_details(stdClass $activity): string {
    $chips = [];
    if (!empty($activity->durationminutes)) {
        $chips[] = html_writer::span((int)$activity->durationminutes . ' ' .
            get_string('learningpathminuteshort', 'format_selfstudy'),
            'format-selfstudy-patheditor-cardchip');
    }
    if (!empty($activity->completionlabel)) {
        $completionclass = empty($activity->hascompletion) ?
            ' format-selfstudy-patheditor-cardchipwarn' : '';
        $chips[] = html_writer::span($activity->completionlabel,
            'format-selfstudy-patheditor-cardchip' . $completionclass);
    }
    if (!empty($activity->availabilitylabel)) {
        $chips[] = html_writer::span($activity->availabilitylabel, 'format-selfstudy-patheditor-cardchip');
    }

    return $chips ? html_writer::div(implode('', $chips), 'format-selfstudy-patheditor-carddetails') : '';
}

/**
 * Renders a shortcut to the Moodle activity settings.
 *
 * @param stdClass $activity
 * @return string
 */
function format_selfstudy_path_editor_render_settings_link(stdClass $activity): string {
    if (empty($activity->editurl)) {
        return '';
    }

    return html_writer::link($activity->editurl, get_string('learningpatheditactivitysettings', 'format_selfstudy'), [
        'class' => 'format-selfstudy-patheditor-cardsettings',
        'target' => '_blank',
        'rel' => 'noopener',
    ]);
}

/**
 * Builds a browser tooltip for an editor activity card.
 *
 * @param stdClass $activity
 * @return string
 */
function format_selfstudy_path_editor_activity_tooltip(stdClass $activity): string {
    $parts = [
        $activity->name,
        $activity->modname . ' · ' . $activity->sectionname,
    ];
    if (!empty($activity->durationminutes)) {
        $parts[] = get_string('activitydurationminutes', 'format_selfstudy') . ': ' .
            (int)$activity->durationminutes;
    }
    if (!empty($activity->completionlabel)) {
        $parts[] = get_string('learningpathcompletionstatus', 'format_selfstudy') . ': ' .
            $activity->completionlabel;
    }
    if (!empty($activity->availabilitylabel)) {
        $parts[] = get_string('learningpathavailability', 'format_selfstudy') . ': ' .
            $activity->availabilitylabel;
    }
    if (trim((string)$activity->learninggoal) !== '') {
        $parts[] = get_string('activitylearninggoal', 'format_selfstudy') . ': ' .
            trim((string)$activity->learninggoal);
    }
    if (trim((string)$activity->competencies) !== '') {
        $parts[] = get_string('activitycompetencies', 'format_selfstudy') . ': ' .
            trim((string)$activity->competencies);
    }

    return implode("\n", $parts);
}

/**
 * Builds searchable text for an editor activity card.
 *
 * @param stdClass $activity
 * @return string
 */
function format_selfstudy_path_editor_activity_search_text(stdClass $activity): string {
    return trim(implode(' ', array_filter([
        $activity->name ?? '',
        $activity->modname ?? '',
        $activity->sectionname ?? '',
        $activity->learninggoal ?? '',
        $activity->competencies ?? '',
        $activity->availabilitylabel ?? '',
        $activity->completionlabel ?? '',
    ], static function($value): bool {
        return trim((string)$value) !== '';
    })));
}

/**
 * Reads a nested POST array and cleans all values as integers.
 *
 * Moodle's optional_param_array() intentionally handles flat arrays only, but
 * alternative groups submit one array of selected cmids per group.
 *
 * @param string $name
 * @return array
 */
function format_selfstudy_path_editor_optional_nested_int_array(string $name): array {
    if (empty($_POST[$name]) || !is_array($_POST[$name])) {
        return [];
    }

    $cleaned = [];
    foreach ($_POST[$name] as $groupkey => $values) {
        $groupkey = clean_param($groupkey, PARAM_INT);
        if (!is_array($values)) {
            continue;
        }

        $cleaned[$groupkey] = [];
        foreach ($values as $value) {
            $value = clean_param($value, PARAM_INT);
            if ($value) {
                $cleaned[$groupkey][] = $value;
            }
        }
    }

    return $cleaned;
}

/**
 * Returns selected station items keyed by course module id.
 *
 * @param stdClass|null $path
 * @return stdClass[]
 */
function format_selfstudy_path_editor_get_selected_station_items(?stdClass $path): array {
    $selected = [];
    if (!$path || empty($path->items)) {
        return $selected;
    }

    foreach ($path->items as $item) {
        if ($item->itemtype !== path_repository::ITEM_STATION || !empty($item->parentid) || empty($item->cmid)) {
            continue;
        }
        $selected[(int)$item->cmid] = $item;
    }

    return $selected;
}

/**
 * Returns alternative groups from a path.
 *
 * @param stdClass|null $path
 * @return stdClass[]
 */
function format_selfstudy_path_editor_get_alternatives(?stdClass $path): array {
    if (!$path || empty($path->items)) {
        return [];
    }

    $children = [];
    foreach ($path->items as $item) {
        if (!empty($item->parentid) && $item->itemtype === path_repository::ITEM_STATION && !empty($item->cmid)) {
            $children[(int)$item->parentid][] = (int)$item->cmid;
        }
    }

    $alternatives = [];
    foreach ($path->items as $item) {
        if ($item->itemtype !== path_repository::ITEM_ALTERNATIVE || !empty($item->parentid)) {
            continue;
        }
        $item->cmids = $children[(int)$item->id] ?? [];
        $alternatives[] = $item;
    }

    usort($alternatives, static function(stdClass $left, stdClass $right): int {
        return ((int)$left->sortorder <=> (int)$right->sortorder) ?: ((int)$left->id <=> (int)$right->id);
    });

    return $alternatives;
}

/**
 * Returns milestones from a path.
 *
 * @param stdClass|null $path
 * @return stdClass[]
 */
function format_selfstudy_path_editor_get_milestones(?stdClass $path): array {
    if (!$path || empty($path->items)) {
        return [];
    }

    $milestones = [];
    foreach ($path->items as $item) {
        if ($item->itemtype === path_repository::ITEM_MILESTONE && empty($item->parentid)) {
            $milestones[] = $item;
        }
    }

    usort($milestones, static function(stdClass $left, stdClass $right): int {
        return ((int)$left->sortorder <=> (int)$right->sortorder) ?: ((int)$left->id <=> (int)$right->id);
    });

    return $milestones;
}

/**
 * Returns the milestone title, falling back to the following activity section.
 *
 * @param stdClass $milestone
 * @param stdClass|null $path
 * @param stdClass[] $activities
 * @return string
 */
function format_selfstudy_path_editor_get_milestone_title(stdClass $milestone, ?stdClass $path, array $activities): string {
    $title = trim((string)($milestone->title ?? ''));
    if ($title !== '') {
        return $title;
    }

    return format_selfstudy_path_editor_get_section_title_after_order((int)$milestone->sortorder, $path, $activities);
}

/**
 * Returns the section title of the next path activity after a sort order.
 *
 * @param int $sortorder
 * @param stdClass|null $path
 * @param stdClass[] $activities
 * @return string
 */
function format_selfstudy_path_editor_get_section_title_after_order(int $sortorder, ?stdClass $path,
        array $activities): string {
    if (!$path || empty($path->items)) {
        return '';
    }

    $activitymap = format_selfstudy_path_editor_activity_map($activities);
    $children = [];
    foreach ($path->items as $item) {
        if (!empty($item->parentid) && !empty($item->cmid)) {
            $children[(int)$item->parentid][] = (int)$item->cmid;
        }
    }

    $candidate = null;
    foreach ($path->items as $item) {
        if (!empty($item->parentid) || (int)$item->sortorder <= $sortorder) {
            continue;
        }
        if ($item->itemtype === path_repository::ITEM_MILESTONE) {
            continue;
        }

        $cmid = 0;
        if ($item->itemtype === path_repository::ITEM_STATION) {
            $cmid = (int)$item->cmid;
        } else if (!empty($children[(int)$item->id])) {
            $cmid = (int)reset($children[(int)$item->id]);
        }
        if (!$cmid || empty($activitymap[$cmid])) {
            continue;
        }

        if (!$candidate || (int)$item->sortorder < (int)$candidate->sortorder) {
            $candidate = $item;
            $candidate->sectionname = $activitymap[$cmid]->sectionname ?? '';
        }
    }

    return $candidate ? (string)$candidate->sectionname : '';
}

/**
 * Builds path items from selected stations, alternatives and milestones.
 *
 * @param int[] $selectedcms
 * @param int[] $orders
 * @param string[] $alternativetitles
 * @param int[] $alternativeorders
 * @param array $alternativecms
 * @param string[] $milestonetitles
 * @param string[] $milestonedescriptions
 * @param int[] $milestoneorders
 * @param stdClass[] $activities
 * @return array
 */
function format_selfstudy_path_editor_build_items(array $selectedcms, array $orders, array $alternativetitles,
        array $alternativeorders, array $alternativecms, array $milestonetitles, array $milestonedescriptions,
        array $milestoneorders, array $activities = []): array {
    $selectedcms = array_values(array_unique(array_map('intval', $selectedcms)));
    $activitymap = format_selfstudy_path_editor_activity_map($activities);
    $items = [];

    foreach ($selectedcms as $index => $cmid) {
        if (!$cmid) {
            continue;
        }
        $items[] = [
            'itemtype' => path_repository::ITEM_STATION,
            'cmid' => $cmid,
            'sortorder' => isset($orders[$cmid]) ? (int)$orders[$cmid] : $index,
        ];
    }

    foreach ($alternativetitles as $index => $title) {
        $title = trim((string)$title);
        $cmids = $alternativecms[$index] ?? [];
        if (!is_array($cmids)) {
            $cmids = [];
        }
        $cmids = array_values(array_unique(array_filter(array_map('intval', $cmids))));
        if ($title === '' || !$cmids) {
            continue;
        }

        $children = [];
        foreach ($cmids as $childindex => $cmid) {
            $children[] = [
                'itemtype' => path_repository::ITEM_STATION,
                'cmid' => $cmid,
                'sortorder' => $childindex,
            ];
        }

        $items[] = [
            'itemtype' => path_repository::ITEM_ALTERNATIVE,
            'title' => $title,
            'sortorder' => isset($alternativeorders[$index]) ? (int)$alternativeorders[$index] : (count($items) + 1),
            'children' => $children,
        ];
    }

    foreach ($milestonetitles as $index => $title) {
        $title = trim((string)$title);
        $hasorder = isset($milestoneorders[$index]) && trim((string)$milestoneorders[$index]) !== '';
        $description = $milestonedescriptions[$index] ?? '';
        if ($title === '' && $hasorder) {
            $title = format_selfstudy_path_editor_get_submitted_milestone_section_title(
                (int)$milestoneorders[$index],
                $selectedcms,
                $orders,
                $alternativeorders,
                $alternativecms,
                $activitymap
            );
        }
        if ($title === '' && trim((string)$description) === '' && !$hasorder) {
            continue;
        }
        if ($title === '') {
            $title = get_string('learningpathmilestone', 'format_selfstudy');
        }

        $items[] = [
            'itemtype' => path_repository::ITEM_MILESTONE,
            'title' => $title,
            'description' => $description,
            'sortorder' => isset($milestoneorders[$index]) ? (int)$milestoneorders[$index] : (count($items) + 1),
        ];
    }

    usort($items, static function(array $left, array $right): int {
        return ($left['sortorder'] <=> $right['sortorder']) ?:
            (($left['cmid'] ?? 0) <=> ($right['cmid'] ?? 0));
    });

    foreach ($items as $index => &$item) {
        $item['sortorder'] = $index;
    }
    unset($item);

    return $items;
}

/**
 * Returns an activity map keyed by course module id.
 *
 * @param stdClass[] $activities
 * @return stdClass[]
 */
function format_selfstudy_path_editor_activity_map(array $activities): array {
    $map = [];
    foreach ($activities as $activity) {
        $map[(int)$activity->id] = $activity;
    }

    return $map;
}

/**
 * Finds the section title for a submitted milestone without a custom title.
 *
 * @param int $sortorder
 * @param int[] $selectedcms
 * @param int[] $orders
 * @param int[] $alternativeorders
 * @param array $alternativecms
 * @param stdClass[] $activitymap
 * @return string
 */
function format_selfstudy_path_editor_get_submitted_milestone_section_title(int $sortorder, array $selectedcms,
        array $orders, array $alternativeorders, array $alternativecms, array $activitymap): string {
    $candidateorder = null;
    $candidatecmid = 0;

    foreach ($selectedcms as $index => $cmid) {
        $cmid = (int)$cmid;
        $order = isset($orders[$cmid]) ? (int)$orders[$cmid] : (int)$index;
        if ($order <= $sortorder || empty($activitymap[$cmid])) {
            continue;
        }
        if ($candidateorder === null || $order < $candidateorder) {
            $candidateorder = $order;
            $candidatecmid = $cmid;
        }
    }

    foreach ($alternativeorders as $index => $order) {
        $order = (int)$order;
        if ($order <= $sortorder || ($candidateorder !== null && $order >= $candidateorder)) {
            continue;
        }
        $cmids = $alternativecms[$index] ?? [];
        if (!is_array($cmids) || !$cmids) {
            continue;
        }
        $cmid = (int)reset($cmids);
        if (empty($activitymap[$cmid])) {
            continue;
        }
        $candidateorder = $order;
        $candidatecmid = $cmid;
    }

    return $candidatecmid && !empty($activitymap[$candidatecmid]->sectionname) ?
        (string)$activitymap[$candidatecmid]->sectionname : '';
}

/**
 * Renders a number input.
 *
 * @param string $name
 * @param string $label
 * @param string $value
 * @return string
 */
function format_selfstudy_path_editor_number_input(string $name, string $label, string $value,
        bool $sortable = false): string {
    $id = 'format-selfstudy-path-' . preg_replace('/[^a-z0-9_-]+/i', '-', $name);
    $attributes = [
        'type' => 'number',
        'name' => $name,
        'id' => $id,
        'value' => $value,
        'min' => 0,
        'step' => 1,
        'class' => 'form-control format-selfstudy-order-input',
    ];
    if ($sortable) {
        $attributes['data-format-selfstudy-order-input'] = '1';
    }

    return html_writer::div(
        html_writer::label($label, $id) .
        html_writer::empty_tag('input', $attributes),
        'form-group'
    );
}

/**
 * Renders controls for drag/drop and keyboard sorting.
 *
 * @return string
 */
function format_selfstudy_path_editor_sort_controls(): string {
    return html_writer::div(
        html_writer::span(get_string('learningpathsortdrag', 'format_selfstudy'),
            'format-selfstudy-sort-handle', [
                'aria-hidden' => 'true',
                'title' => get_string('learningpathsortdrag', 'format_selfstudy'),
            ]) .
        html_writer::tag('button', get_string('learningpathsortup', 'format_selfstudy'), [
            'type' => 'button',
            'class' => 'btn btn-secondary btn-sm format-selfstudy-sort-button',
            'data-format-selfstudy-sort-move' => 'up',
        ]) .
        html_writer::tag('button', get_string('learningpathsortdown', 'format_selfstudy'), [
            'type' => 'button',
            'class' => 'btn btn-secondary btn-sm format-selfstudy-sort-button',
            'data-format-selfstudy-sort-move' => 'down',
        ]),
        'format-selfstudy-sort-controls'
    );
}

/**
 * Renders a text input.
 *
 * @param string $name
 * @param string $label
 * @param string $value
 * @return string
 */
function format_selfstudy_path_editor_text_input(string $name, string $label, string $value): string {
    $id = 'format-selfstudy-path-' . $name;
    return html_writer::div(
        html_writer::label($label, $id) .
        html_writer::empty_tag('input', [
            'type' => 'text',
            'name' => $name,
            'id' => $id,
            'value' => $value,
            'class' => 'form-control',
        ]),
        'form-group'
    );
}

/**
 * Renders a textarea.
 *
 * @param string $name
 * @param string $label
 * @param string $value
 * @return string
 */
function format_selfstudy_path_editor_textarea(string $name, string $label, string $value): string {
    $id = 'format-selfstudy-path-' . $name;
    return html_writer::div(
        html_writer::label($label, $id) .
        html_writer::tag('textarea', s($value), [
            'name' => $name,
            'id' => $id,
            'class' => 'form-control',
            'rows' => 4,
        ]),
        'form-group'
    );
}
