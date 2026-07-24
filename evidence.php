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

$PAGE->set_url(new moodle_url('/course/format/selfstudy/evidence.php', ['id' => $course->id]));
$PAGE->set_context(context_course::instance($course->id));
$PAGE->set_course($course);
$PAGE->set_pagelayout('course');
$PAGE->set_title(get_string('evidencetitle', 'format_selfstudy'));
$PAGE->set_heading(format_string($course->fullname));

$baseview = \format_selfstudy\local\base_view::create($course, (int)$USER->id);
$renderer = new \format_selfstudy\local\base_renderer();

echo $OUTPUT->header();
echo html_writer::start_div('format-selfstudy-dashboard format-selfstudy-evidence-page');
echo html_writer::start_div('format-selfstudy-personalpath-pagehead');
echo $OUTPUT->heading(get_string('evidencetitle', 'format_selfstudy'), 2);
echo html_writer::link(new moodle_url('/course/view.php', ['id' => $course->id]),
    get_string('backtocourse', 'format_selfstudy'), ['class' => 'btn btn-secondary']);
echo html_writer::end_div();

if (!empty($baseview->path)) {
    $evidence = (new \format_selfstudy\local\path_evidence_service())
        ->build_evidence($course, (int)$baseview->path->id, (int)$USER->id);
    $html = $renderer->render_evidence($evidence);
    if ($html !== '') {
        echo $html;
    } else {
        echo $OUTPUT->notification(get_string('evidenceempty', 'format_selfstudy'),
            \core\output\notification::NOTIFY_INFO);
    }
} else {
    echo $OUTPUT->notification(get_string('learningpathnone', 'format_selfstudy'),
        \core\output\notification::NOTIFY_INFO);
}

echo html_writer::end_div();
echo $OUTPUT->footer();
