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

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);
require_login($course);

$format = course_get_format($course);
if ($format->get_format() !== 'selfstudy') {
    throw new moodle_exception('invalidcourseformat', 'error');
}

$FORMAT_SELFSTUDY_FUNCTIONS_ONLY = true;
require_once(__DIR__ . '/format.php');

$PAGE->set_url(new moodle_url('/course/format/selfstudy/personal_path.php', ['id' => $course->id]));
$PAGE->set_context(context_course::instance($course->id));
$PAGE->set_course($course);
$PAGE->set_pagelayout('course');
$PAGE->set_title(get_string('learningpathpersonal', 'format_selfstudy'));
$PAGE->set_heading(format_string($course->fullname));

$formatoptions = $format->get_format_options();
$modinfo = get_fast_modinfo($course);
$sections = $modinfo->get_section_info_all();
$completion = new completion_info($course);
$summaries = format_selfstudy_get_learning_summaries($course, $format, $modinfo, $sections, $completion);
$repository = new \format_selfstudy\local\path_repository();
$activepath = $repository->get_active_path((int)$course->id, (int)$USER->id);
if ($activepath && empty($activepath->enabled)) {
    $activepath = null;
}

echo $OUTPUT->header();
echo html_writer::start_div('format-selfstudy-dashboard');
echo $OUTPUT->heading(get_string('learningpathpersonal', 'format_selfstudy'), 2);
echo html_writer::link(new moodle_url('/course/view.php', ['id' => $course->id]),
    get_string('backtocourse', 'format_selfstudy'), ['class' => 'btn btn-secondary']);

if (!empty($formatoptions['allowpersonalpaths'])) {
    echo format_selfstudy_render_personal_path_builder($course, $summaries, $activepath);
} else {
    echo $OUTPUT->notification(get_string('learningpathnone', 'format_selfstudy'),
        \core\output\notification::NOTIFY_INFO);
}

echo html_writer::end_div();
echo $OUTPUT->footer();
