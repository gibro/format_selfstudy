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

$context = context_course::instance($course->id);
require_capability('moodle/course:update', $context);

$format = course_get_format($course);
if ($format->get_format() !== 'selfstudy') {
    throw new moodle_exception('invalidcourseformat', 'error');
}

$PAGE->set_url(new moodle_url('/course/format/selfstudy/escape_guide.php', ['id' => $course->id]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('course');
$PAGE->set_title(get_string('escapeguide_title', 'format_selfstudy'));
$PAGE->set_heading(format_string($course->fullname));

require_once(__DIR__ . '/experience/escape/classes/guide.php');

echo $OUTPUT->header();
echo html_writer::start_div('format-selfstudy-dashboard format-selfstudy-escapeguide-page');
echo html_writer::start_div('format-selfstudy-personalpath-pagehead');
echo $OUTPUT->heading(get_string('escapeguide_title', 'format_selfstudy'), 2);
echo html_writer::link(new moodle_url('/course/view.php', ['id' => $course->id]),
    get_string('backtocourse', 'format_selfstudy'), ['class' => 'btn btn-secondary']);
echo html_writer::end_div();
echo html_writer::div(get_string('escapeguide_lead', 'format_selfstudy'), 'format-selfstudy-escapeguide-lead');

echo (new \selfstudyexperience_escape\guide())->render($course);

echo html_writer::end_div();
echo $OUTPUT->footer();
