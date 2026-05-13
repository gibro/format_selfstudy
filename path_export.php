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
$pathid = required_param('pathid', PARAM_INT);

$course = get_course($courseid);
require_login($course);

$coursecontext = context_course::instance($course->id);
require_capability('moodle/course:update', $coursecontext);

$format = course_get_format($course);
if ($format->get_format() !== 'selfstudy') {
    throw new moodle_exception('invalidcourseformat', 'error');
}

require_sesskey();

$transfer = new path_transfer();
$payload = $transfer->export_path($course, $pathid);
$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    throw new moodle_exception('error');
}

$filename = clean_param($payload['path']['name'] ?? 'learning-path', PARAM_FILE);
if ($filename === '') {
    $filename = 'learning-path';
}
$filename .= '.json';

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($json));
echo $json;
die();
