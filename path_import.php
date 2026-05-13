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

use format_selfstudy\local\path_transfer;

$courseid = required_param('id', PARAM_INT);
$json = optional_param('importjson', '', PARAM_RAW);

$course = get_course($courseid);
require_login($course);

$coursecontext = context_course::instance($course->id);
require_capability('moodle/course:update', $coursecontext);

$format = course_get_format($course);
if ($format->get_format() !== 'selfstudy') {
    throw new moodle_exception('invalidcourseformat', 'error');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $baseurl = new moodle_url('/course/format/selfstudy/path_import.php', ['id' => $course->id]);
    $PAGE->set_url($baseurl);
    $PAGE->set_context($coursecontext);
    $PAGE->set_course($course);
    $PAGE->set_pagelayout('course');
    $PAGE->set_title(get_string('learningpathimport', 'format_selfstudy'));
    $PAGE->set_heading(format_string($course->fullname));
    $PAGE->set_secondary_active_tab('coursereuse');

    echo $OUTPUT->header();
    echo html_writer::start_div('format-selfstudy-pathtransfer');
    echo $OUTPUT->heading(get_string('learningpathimport', 'format_selfstudy'), 2);
    echo html_writer::start_div('format-selfstudy-patheditor-toolbar');
    echo html_writer::link(new moodle_url('/course/format/selfstudy/path_editor.php', ['id' => $course->id]),
        get_string('learningpatheditor', 'format_selfstudy'), ['class' => 'btn btn-secondary']);
    echo html_writer::link(new moodle_url('/course/view.php', ['id' => $course->id]),
        get_string('viewcourse', 'format_selfstudy'), ['class' => 'btn btn-secondary']);
    echo html_writer::end_div();
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $baseurl->out(false),
        'enctype' => 'multipart/form-data',
        'class' => 'format-selfstudy-pathtransfer-form',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::div(
        html_writer::label(get_string('learningpathimportfile', 'format_selfstudy'), 'format-selfstudy-import-file') .
        html_writer::empty_tag('input', [
            'type' => 'file',
            'name' => 'importfile',
            'id' => 'format-selfstudy-import-file',
            'accept' => 'application/json,.json',
            'class' => 'form-control',
        ]),
        'form-group'
    );
    echo html_writer::div(
        html_writer::label(get_string('learningpathimportjson', 'format_selfstudy'), 'format-selfstudy-import-json') .
        html_writer::tag('textarea', '', [
            'name' => 'importjson',
            'id' => 'format-selfstudy-import-json',
            'class' => 'form-control',
            'rows' => 8,
        ]),
        'form-group'
    );
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-primary',
        'value' => get_string('learningpathimportbutton', 'format_selfstudy'),
    ]);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    die();
}

require_sesskey();

if ($json === '' && !empty($_FILES['importfile']['tmp_name']) && is_readable($_FILES['importfile']['tmp_name'])) {
    $json = file_get_contents($_FILES['importfile']['tmp_name']);
}

if (trim($json) === '') {
    throw new moodle_exception('learningpathimportmissing', 'format_selfstudy');
}

$transfer = new path_transfer();
$payload = $transfer->decode_json($json);
$pathid = $transfer->import_path($course, $payload);

redirect(new moodle_url('/course/format/selfstudy/path_editor.php', [
    'id' => $course->id,
    'pathid' => $pathid,
]), get_string('learningpathimportdone', 'format_selfstudy'), null, \core\output\notification::NOTIFY_SUCCESS);
