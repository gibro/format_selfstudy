<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Ajax endpoint for the Learning-Nuggets feed.
 *
 * Two actions, both learner-scoped and side-effect free for learning logic:
 * - viewed: marks a view-completion activity as viewed via Moodle core
 *   (completion_info::set_module_viewed) once the learner has read the
 *   embedded nugget in the feed.
 * - feed: re-renders the feed fragment so the page can refresh progress and
 *   unlocked nuggets without a reload.
 *
 * @package format_selfstudy
 */

define('AJAX_SCRIPT', true);

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
$action = required_param('action', PARAM_ALPHA);

$course = get_course($courseid);
require_login($course);
require_sesskey();

if (course_get_format($course)->get_format() !== 'selfstudy') {
    throw new moodle_exception('invalidcourseformat', 'error');
}

$result = ['ok' => false, 'html' => ''];

if ($action === 'viewed') {
    $cmid = required_param('cmid', PARAM_INT);
    $modinfo = get_fast_modinfo($course);
    $cm = $modinfo->get_cm($cmid);
    if ($cm->uservisible) {
        $completion = new completion_info($course);
        if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC &&
                (int)$cm->completionview === COMPLETION_VIEW_REQUIRED) {
            $completion->set_module_viewed($cm);
            $result['ok'] = true;
        }
    }
}

// Both actions return a fresh feed fragment, so one round trip is enough to
// show new progress and any nuggets that just unlocked.
try {
    $baseview = \format_selfstudy\local\base_view::create($course, (int)$USER->id);
    $registry = new \format_selfstudy\local\experience_registry();
    foreach ($registry->get_course_experiences($course, $baseview) as $entry) {
        if ($entry->component !== 'selfstudyexperience_nuggets') {
            continue;
        }
        if ($entry->status === \format_selfstudy\local\experience_registry::STATUS_AVAILABLE &&
                $entry->renderer instanceof \selfstudyexperience_nuggets\renderer) {
            $result['html'] = $entry->renderer->render_fragment($course, $baseview, $entry->config);
            if ($action === 'feed') {
                $result['ok'] = true;
            }
        }
        break;
    }
} catch (Throwable $exception) {
    debugging('Nugget feed fragment failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result);
