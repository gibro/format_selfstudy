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

use format_selfstudy\local\path_repository;

$courseid = required_param('id', PARAM_INT);
$itemid = required_param('itemid', PARAM_INT);

$course = get_course($courseid);
require_login($course);
require_sesskey();

$context = context_course::instance($course->id);
if (!is_enrolled($context, $USER, '', true)) {
    throw new require_login_exception('Not enrolled in this course.');
}

$format = course_get_format($course);
if ($format->get_format() !== 'selfstudy') {
    throw new moodle_exception('invalidcourseformat', 'error');
}

$repository = new path_repository();
$item = $repository->get_item($itemid);
$path = $item ? $repository->get_path((int)$item->pathid) : null;
if (!$item || !$path || (int)$path->courseid !== (int)$course->id ||
        $item->itemtype !== path_repository::ITEM_MILESTONE) {
    throw new moodle_exception('invalidrecord', 'error');
}

redirect(new moodle_url('/course/view.php', ['id' => $course->id]),
    get_string('learningpathmilestonecompletionderived', 'format_selfstudy'),
    null,
    \core\output\notification::NOTIFY_INFO);
