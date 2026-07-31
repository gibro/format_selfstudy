<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Full-screen Lern-Nuggets app (home overview + typed card player).
 *
 * Rendered in the embedded page layout so the micro-learning feed gets a
 * clean full-screen canvas without the surrounding Moodle chrome, matching
 * the IG Metall design handoff. Content is served from the sample content
 * provider; learner state (streak, quiz answers, bookmarks, card index) is
 * kept client-side for this prototype.
 *
 * @package format_selfstudy
 */

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

if (course_get_format($course)->get_format() !== 'selfstudy') {
    throw new moodle_exception('invalidcourseformat', 'error');
}

require_once(__DIR__ . '/experience/nuggets/classes/inline_content.php');
require_once(__DIR__ . '/experience/nuggets/classes/card_builder.php');
require_once(__DIR__ . '/experience/nuggets/classes/app_renderer.php');

// Navigation model: course config wins, ?nav= overrides for previewing.
$navmodel = 'snap';
try {
    $repo = new \format_selfstudy\local\experience_repository();
    $entry = $repo->get_course_experience((int)$course->id, 'selfstudyexperience_nuggets');
    if ($entry) {
        $config = $repo->decode_config($entry);
        if (!empty($config->navmodel) && in_array($config->navmodel, ['snap', 'story'], true)) {
            $navmodel = $config->navmodel;
        }
    }
} catch (Throwable $exception) {
    $navmodel = 'snap';
}
$navoverride = optional_param('nav', '', PARAM_ALPHA);
if (in_array($navoverride, ['snap', 'story'], true)) {
    $navmodel = $navoverride;
}

$pageurl = new moodle_url('/course/format/selfstudy/nuggets_app.php', ['id' => $course->id]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(get_string('nuggetsapp_title', 'format_selfstudy'));
$PAGE->add_body_class('format-selfstudy-nq-body');

$backurl = new moodle_url('/course/view.php', ['id' => $course->id]);

$PAGE->requires->js_call_amd('format_selfstudy/nuggets_app', 'init', [[
    'navModel' => $navmodel,
    'courseId' => (int)$course->id,
    'storageKey' => 'format_selfstudy_nq_' . (int)$course->id,
]]);

echo $OUTPUT->header();

$builder = new \selfstudyexperience_nuggets\card_builder();
$renderer = new \selfstudyexperience_nuggets\app_renderer();
echo $renderer->render(
    $builder->home($course),
    $builder->nuggets($course),
    $backurl
);

echo $OUTPUT->footer();
