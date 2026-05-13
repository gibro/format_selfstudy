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

$PAGE->set_url(new moodle_url('/course/format/selfstudy/accessible_path.php', ['id' => $course->id]));
$PAGE->set_context(context_course::instance($course->id));
$PAGE->set_course($course);
$PAGE->set_pagelayout('course');
$PAGE->set_title(get_string('learningpathaccessibleview', 'format_selfstudy'));
$PAGE->set_heading(format_string($course->fullname));

$formatoptions = $format->get_format_options();
$pathpointcolor = format_selfstudy_normalise_hex_color($formatoptions['pathpointcolor'] ?? '#6f1ab1');
$showlockedactivities = !array_key_exists('showlockedactivities', $formatoptions) ||
    !empty($formatoptions['showlockedactivities']);
$modinfo = get_fast_modinfo($course);
$mainmapcm = null;
if (!empty($formatoptions['mainlearningmap'])) {
    try {
        $mainmapcm = $modinfo->get_cm((int)$formatoptions['mainlearningmap']);
    } catch (Throwable $exception) {
        $mainmapcm = null;
    }
    if ($mainmapcm && !$mainmapcm->uservisible) {
        $mainmapcm = null;
    }
}

$baseview = \format_selfstudy\local\base_view::create($course, (int)$USER->id);
$activepath = $baseview->path;
$progress = $baseview->progress;
$outline = $baseview->outline;

echo $OUTPUT->header();
echo html_writer::start_div('format-selfstudy-dashboard');
echo $OUTPUT->heading(get_string('learningpathaccessibleview', 'format_selfstudy'), 2);
echo html_writer::link(new moodle_url('/course/view.php', ['id' => $course->id]),
    get_string('backtocourse', 'format_selfstudy'), ['class' => 'btn btn-secondary']);

if ($activepath && $outline) {
    echo format_selfstudy_render_accessible_path_view($activepath, $progress, $outline, $mainmapcm, $pathpointcolor,
        $showlockedactivities);
} else {
    echo $OUTPUT->notification(get_string('learningpathnone', 'format_selfstudy'),
        \core\output\notification::NOTIFY_INFO);
}

echo html_writer::end_div();
echo $OUTPUT->footer();
