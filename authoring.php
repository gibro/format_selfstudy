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

use format_selfstudy\local\authoring_renderer;
use format_selfstudy\local\authoring_workflow;

$courseid = required_param('id', PARAM_INT);
$pathid = optional_param('pathid', 0, PARAM_INT);

$course = get_course($courseid);
require_login($course);

$coursecontext = context_course::instance($course->id);
require_capability('moodle/course:update', $coursecontext);

$format = course_get_format($course);
if ($format->get_format() !== 'selfstudy') {
    throw new moodle_exception('invalidcourseformat', 'error');
}

$baseurl = new moodle_url('/course/format/selfstudy/authoring.php', ['id' => $course->id]);
$workflow = new authoring_workflow();
$state = $workflow->get_state($course, $pathid);
$renderer = new authoring_renderer();

$PAGE->set_url($baseurl, $state->pathid ? ['pathid' => $state->pathid] : []);
$PAGE->set_context($coursecontext);
$PAGE->set_course($course);
$PAGE->set_pagelayout('course');
$PAGE->set_title(get_string('authoringworkflow', 'format_selfstudy'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_secondary_active_tab('coursereuse');

echo $OUTPUT->header();
echo html_writer::start_div('format-selfstudy-authoring');
echo $OUTPUT->heading(get_string('authoringworkflow', 'format_selfstudy'), 2);
echo html_writer::tag('p', get_string('authoringworkflowintro', 'format_selfstudy'), ['class' => 'text-muted']);

echo html_writer::start_div('format-selfstudy-patheditor-toolbar');
if (!empty($state->paths)) {
    echo html_writer::start_tag('form', [
        'method' => 'get',
        'action' => $baseurl->out(false),
        'class' => 'format-selfstudy-patheditor-select',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $course->id]);
    echo html_writer::label(get_string('learningpathselect', 'format_selfstudy'), 'format-selfstudy-authoring-path-select');
    $options = [];
    foreach ($state->paths as $path) {
        $options[(int)$path->id] = format_string($path->name);
    }
    echo html_writer::select($options, 'pathid', $state->pathid, false, [
        'id' => 'format-selfstudy-authoring-path-select',
        'onchange' => 'this.form.submit()',
    ]);
    echo html_writer::end_tag('form');
}
echo html_writer::link(new moodle_url('/course/format/selfstudy/path_editor.php', ['id' => $course->id] +
    ($state->pathid ? ['pathid' => $state->pathid] : [])), get_string('learningpatheditor', 'format_selfstudy'),
    ['class' => 'btn btn-secondary']);
if ($state->pathid) {
    echo html_writer::link(new moodle_url('/course/format/selfstudy/path_diagnosis.php', [
        'id' => $course->id,
        'pathid' => $state->pathid,
    ]), get_string('learningpathsyncpreview', 'format_selfstudy'), ['class' => 'btn btn-secondary']);
}
echo html_writer::link(new moodle_url('/course/format/selfstudy/experience_settings.php', ['id' => $course->id]),
    get_string('experiencesettings', 'format_selfstudy'), ['class' => 'btn btn-secondary']);
echo html_writer::link(new moodle_url('/course/view.php', ['id' => $course->id]),
    get_string('viewcourse', 'format_selfstudy'), ['class' => 'btn btn-secondary']);
if (!empty($state->activerevision)) {
    echo html_writer::link(new moodle_url('/course/format/selfstudy/accessible_path.php', ['id' => $course->id]),
        get_string('authoringworkflowactionlearnerpreview', 'format_selfstudy'), ['class' => 'btn btn-secondary']);
}
echo html_writer::end_div();

echo html_writer::div(
    html_writer::tag('h3', get_string('authoringworkflownextaction', 'format_selfstudy')) .
    ($state->nextaction ? html_writer::div(s($state->nextaction->summary), 'format-selfstudy-authoring-next') : '') .
    ($state->nextaction && !empty($state->nextaction->actionurl) ?
        html_writer::link($state->nextaction->actionurl, $state->nextaction->actionlabel,
            ['class' => 'btn btn-primary']) : ''),
    'format-selfstudy-authoring-nextpanel'
);

echo $renderer->publish_status($state);
echo $renderer->workflow($state);
echo $renderer->issues($state);
echo $renderer->revisions($state);

echo html_writer::end_div();
echo $OUTPUT->footer();
