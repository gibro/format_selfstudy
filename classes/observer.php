<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace format_selfstudy;

defined('MOODLE_INTERNAL') || die();

/**
 * Event handlers of the dashboard format.
 *
 * @package    format_selfstudy
 */
class observer {

    /**
     * Removes the contact persons of a deleted course.
     *
     * The hero image goes with the course context; the contacts live in a
     * table of their own and would stay behind. A course that only switched to
     * another format keeps them, like Moodle keeps the options of a format.
     *
     * @param \core\event\course_deleted $event
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;

        $DB->delete_records('format_selfstudy_contacts', ['courseid' => (int)$event->objectid]);
    }
}
