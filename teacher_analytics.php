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

$format = course_get_format($course);
if ($format->get_format() !== 'selfstudy') {
    throw new moodle_exception('invalidcourseformat', 'error');
}

$coursecontext = context_course::instance($course->id);
require_capability('moodle/course:update', $coursecontext);

$FORMAT_SELFSTUDY_FUNCTIONS_ONLY = true;
require_once(__DIR__ . '/format.php');

$PAGE->set_url(new moodle_url('/course/format/selfstudy/teacher_analytics.php', ['id' => $course->id]));
$PAGE->set_context($coursecontext);
$PAGE->set_course($course);
$PAGE->set_pagelayout('course');
$PAGE->set_title(get_string('teacheranalytics', 'format_selfstudy'));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo html_writer::start_div('format-selfstudy-dashboard');
echo $OUTPUT->heading(get_string('teacheranalytics', 'format_selfstudy'), 2);
echo html_writer::link(new moodle_url('/course/view.php', ['id' => $course->id]),
    get_string('backtocourse', 'format_selfstudy'), ['class' => 'btn btn-secondary']);
echo format_selfstudy_render_teacher_analytics($course, $format);
echo html_writer::end_div();
echo $OUTPUT->footer();
