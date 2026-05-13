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
$pathid = optional_param('pathid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$course = get_course($courseid);
require_login($course);
require_sesskey();

$context = context_course::instance($course->id);
require_capability('moodle/course:view', $context);

$format = course_get_format($course);
if ($format->get_format() !== 'selfstudy') {
    throw new moodle_exception('invalidcourseformat', 'error');
}

$repository = new path_repository();
$redirecturl = new moodle_url('/course/view.php', ['id' => $course->id]);

if ($action === 'personal') {
    $templatepathid = optional_param('templatepathid', 0, PARAM_INT);
    if ($templatepathid) {
        $milestonekeys = optional_param_array('milestonekeys', [], PARAM_ALPHANUMEXT);
        try {
            $repository->create_personal_path_from_template($course, (int)$USER->id, $templatepathid, $milestonekeys);
            redirect($redirecturl, get_string('learningpathpersonalsaved', 'format_selfstudy'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        } catch (Throwable $exception) {
            redirect($redirecturl, get_string('learningpathpersonalempty', 'format_selfstudy'), null,
                \core\output\notification::NOTIFY_WARNING);
        }
    }

    $sectionids = optional_param_array('sectionids', [], PARAM_INT);
    $format = course_get_format($course);
    $modinfo = get_fast_modinfo($course);
    foreach ($modinfo->get_section_info_all() as $section) {
        if (!$section->uservisible) {
            continue;
        }
        $options = $format->get_format_options($section);
        if (($options['pathkind'] ?? 'required') !== 'optional') {
            $sectionids[] = (int)$section->id;
        }
    }
    $sectionids = array_values(array_unique(array_filter(array_map('intval', $sectionids))));
    if (!$sectionids) {
        redirect($redirecturl, get_string('learningpathpersonalempty', 'format_selfstudy'), null,
            \core\output\notification::NOTIFY_WARNING);
    }

    try {
        $repository->create_personal_path_from_sections($course, (int)$USER->id, $sectionids);
        redirect($redirecturl, get_string('learningpathpersonalsaved', 'format_selfstudy'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    } catch (Throwable $exception) {
        redirect($redirecturl, get_string('learningpathpersonalempty', 'format_selfstudy'), null,
            \core\output\notification::NOTIFY_WARNING);
    }
}

if (!$pathid) {
    throw new moodle_exception('missingparam', 'error', '', 'pathid');
}

$path = $repository->get_path($pathid);
if (!$path || (int)$path->courseid !== (int)$course->id || empty($path->enabled) ||
        ((int)($path->userid ?? 0) !== 0 && (int)$path->userid !== (int)$USER->id)) {
    throw new moodle_exception('invalidrecord', 'error');
}

$repository->set_active_path($course->id, $USER->id, $pathid);

redirect($redirecturl,
    get_string('learningpathselected', 'format_selfstudy'), null,
    \core\output\notification::NOTIFY_SUCCESS);
