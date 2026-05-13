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

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);
require_login($course);

$coursecontext = context_course::instance($course->id);
require_capability('moodle/course:update', $coursecontext);

$format = course_get_format($course);
if ($format->get_format() !== 'selfstudy') {
    throw new moodle_exception('invalidcourseformat', 'error');
}

$url = new moodle_url('/course/format/selfstudy/experience_settings.php', ['id' => $course->id]);
$repository = new \format_selfstudy\local\experience_repository();
$registry = new \format_selfstudy\local\experience_registry($repository);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $components = optional_param_array('components', [], PARAM_COMPONENT);
    $enabled = optional_param_array('enabled', [], PARAM_BOOL);
    $sortorders = optional_param_array('sortorder', [], PARAM_INT);
    $configs = optional_param_array('configjson', [], PARAM_RAW);
    $schemas = optional_param_array('configschema', [], PARAM_INT);
    $missing = optional_param_array('missing', [], PARAM_BOOL);

    foreach ($components as $index => $component) {
        $config = json_decode((string)($configs[$index] ?? '{}'), true);
        if (!is_array($config) || json_last_error() !== JSON_ERROR_NONE) {
            $config = [];
        }

        $ismissing = !empty($missing[$index]);
        $repository->save_course_experience((int)$course->id, $component, $config,
            !$ismissing && !empty($enabled[$index]), (int)($sortorders[$index] ?? $index),
            max(1, (int)($schemas[$index] ?? 1)), $ismissing);
    }

    redirect($url, get_string('experiencesettingssaved', 'format_selfstudy'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

$PAGE->set_url($url);
$PAGE->set_context($coursecontext);
$PAGE->set_course($course);
$PAGE->set_pagelayout('course');
$PAGE->set_title(get_string('experiencesettings', 'format_selfstudy'));
$PAGE->set_heading(format_string($course->fullname));

$entries = $registry->get_course_experiences($course);

echo $OUTPUT->header();
echo html_writer::start_div('format-selfstudy-experience-settings');
echo $OUTPUT->heading(get_string('experiencesettings', 'format_selfstudy'), 2);
echo html_writer::div(get_string('experienceintro', 'format_selfstudy'), 'alert alert-info');

echo html_writer::start_div('format-selfstudy-patheditor-toolbar');
echo html_writer::link(new moodle_url('/course/format/selfstudy/path_editor.php', ['id' => $course->id]),
    get_string('learningpatheditor', 'format_selfstudy'), ['class' => 'btn btn-secondary']);
echo html_writer::link(new moodle_url('/course/view.php', ['id' => $course->id]),
    get_string('viewcourse', 'format_selfstudy'), ['class' => 'btn btn-secondary']);
echo html_writer::end_div();

if (!$entries) {
    echo $OUTPUT->notification(get_string('experiencenoneavailable', 'format_selfstudy'),
        \core\output\notification::NOTIFY_INFO);
} else {
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $url->out(false),
        'class' => 'format-selfstudy-experience-form',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    echo html_writer::start_tag('table', ['class' => 'generaltable']);
    echo html_writer::start_tag('thead');
    echo html_writer::tag('tr',
        html_writer::tag('th', get_string('experience', 'format_selfstudy')) .
        html_writer::tag('th', get_string('status')) .
        html_writer::tag('th', get_string('enabled', 'moodle')) .
        html_writer::tag('th', get_string('sortorder', 'format_selfstudy'))
    );
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach (array_values($entries) as $index => $entry) {
        $configjson = json_encode($entry->config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($configjson === false) {
            $configjson = '{}';
        }

        $enabledattrs = [
            'type' => 'checkbox',
            'name' => 'enabled[' . $index . ']',
            'value' => 1,
        ];
        if ($entry->enabled && !$entry->missing) {
            $enabledattrs['checked'] = 'checked';
        }
        if ($entry->missing) {
            $enabledattrs['disabled'] = 'disabled';
        }

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td',
            html_writer::tag('strong', format_string($entry->name)) .
            html_writer::div(s($entry->component), 'text-muted') .
            ($entry->description !== '' ? html_writer::div(format_text($entry->description, FORMAT_PLAIN),
                'text-muted') : '')
        );
        echo html_writer::tag('td', get_string('experiencestatus' . $entry->status, 'format_selfstudy'));
        echo html_writer::tag('td',
            html_writer::empty_tag('input', $enabledattrs) .
            html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'components[' . $index . ']',
                'value' => $entry->component,
            ]) .
            html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'configjson[' . $index . ']',
                'value' => $configjson,
            ]) .
            html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'configschema[' . $index . ']',
                'value' => $entry->schema,
            ]) .
            html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'missing[' . $index . ']',
                'value' => $entry->missing ? 1 : 0,
            ])
        );
        echo html_writer::tag('td', html_writer::empty_tag('input', [
            'type' => 'number',
            'name' => 'sortorder[' . $index . ']',
            'value' => $entry->sortorder,
            'class' => 'form-control',
            'min' => 0,
            'step' => 1,
        ]));
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-primary',
        'value' => get_string('savechanges'),
    ]);
    echo html_writer::end_tag('form');
}

echo html_writer::end_div();
echo $OUTPUT->footer();
