<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace format_selfstudy;

/**
 * Hook callbacks for the selfstudy course format.
 *
 * @package    format_selfstudy
 */
class hook_callbacks {

    /**
     * Handles the inline editor form submissions before the page header is sent.
     *
     * The inline editors of this format render inside course/view.php, and Moodle
     * includes format.php only after the header has been printed. A POST handled
     * there can no longer produce a Location header, so it would end in a delayed
     * interstitial page. This callback runs at the start of the header, while a
     * real redirect is still possible.
     *
     * @param \core\hook\output\before_http_headers $hook
     */
    public static function before_http_headers(\core\hook\output\before_http_headers $hook): void {
        global $CFG, $PAGE;

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }

        $handlers = [
            'selfstudypublish' => 'handle_publish',
            'selfstudypatheditor' => 'handle_path_editor',
            'selfstudyactivitysettings' => 'handle_activity_settings',
        ];
        $handler = '';
        foreach ($handlers as $param => $method) {
            if (optional_param($param, 0, PARAM_BOOL)) {
                $handler = $method;
                break;
            }
        }
        if ($handler === '') {
            return;
        }

        // Only the inline editors on the course page are handled here. The standalone
        // pages process their own POST before printing anything.
        if ($PAGE->pagetype !== 'course-view-selfstudy') {
            return;
        }

        $course = $PAGE->course ?? null;
        if (!$course || (int)$course->id === (int)SITEID || $course->format !== 'selfstudy') {
            return;
        }

        $coursecontext = \context_course::instance((int)$course->id);
        if (!has_capability('moodle/course:update', $coursecontext)) {
            return;
        }

        require_once($CFG->dirroot . '/course/format/selfstudy/lib.php');
        self::$handler($course);
    }

    /**
     * Publishes a learning path straight from the workflow step.
     *
     * @param \stdClass $course
     */
    private static function handle_publish(\stdClass $course): void {
        require_sesskey();

        $courseurl = new \moodle_url('/course/view.php', ['id' => $course->id]);
        $pathid = optional_param('pathid', 0, PARAM_INT);
        $repository = new \format_selfstudy\local\path_repository();
        $path = $pathid ? $repository->get_path($pathid) : null;
        if (!$path || (int)$path->courseid !== (int)$course->id || (int)($path->userid ?? 0) !== 0) {
            \format_selfstudy_redirect_after_save($courseurl,
                get_string('authoringpublishstatusnopath', 'format_selfstudy'),
                \core\output\notification::NOTIFY_WARNING);
        }

        $result = (new \format_selfstudy\local\path_publish_service($repository))
            ->publish_course_path($course, (int)$pathid);
        if (!empty($result->published)) {
            \format_selfstudy_redirect_after_save($courseurl,
                get_string('learningpathpublished', 'format_selfstudy', (object)[
                    'written' => $result->written,
                    'skipped' => $result->skipped,
                    'revision' => $result->revision,
                ]));
        }

        \format_selfstudy_redirect_after_save($courseurl,
            get_string('learningpathpublishblocked', 'format_selfstudy') .
                \html_writer::alist(array_map('s', $result->errors)),
            \core\output\notification::NOTIFY_WARNING);
    }

    /**
     * Saves a learning path submitted by the inline editor.
     *
     * @param \stdClass $course
     */
    private static function handle_path_editor(\stdClass $course): void {
        self::load_path_editor_functions();

        format_selfstudy_path_editor_handle_post(
            $course,
            new \moodle_url('/course/view.php', ['id' => $course->id, 'selfstudypatheditor' => 1]),
            optional_param('pathid', 0, PARAM_INT),
            optional_param('action', '', PARAM_ALPHA)
        );
    }

    /**
     * Saves the inline activity settings panel.
     *
     * @param \stdClass $course
     */
    private static function handle_activity_settings(\stdClass $course): void {
        self::load_path_editor_functions();

        format_selfstudy_path_editor_handle_activity_settings_post(
            $course,
            new \moodle_url('/course/view.php', ['id' => $course->id, 'selfstudyactivitysettings' => 1])
        );
    }

    /**
     * Loads the editor helper functions without running the editor page.
     */
    private static function load_path_editor_functions(): void {
        global $CFG, $FORMAT_SELFSTUDY_PATH_EDITOR_FUNCTIONS_ONLY;

        $FORMAT_SELFSTUDY_PATH_EDITOR_FUNCTIONS_ONLY = true;
        require_once($CFG->dirroot . '/course/format/selfstudy/path_editor.php');
    }
}
