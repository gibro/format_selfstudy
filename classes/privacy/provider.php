<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace format_selfstudy\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for the self-directed learning course format.
 *
 * The format only stores the course contact persons shown in the course hero.
 * Everything about learning paths, progress and milestones belongs to
 * local_selfstudy and is reported by that plugin's provider.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider {

    /**
     * Returns metadata about the user data stored by this plugin.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('format_selfstudy_contacts', [
            'courseid' => 'privacy:metadata:contacts:courseid',
            'userid' => 'privacy:metadata:contacts:userid',
            'roles' => 'privacy:metadata:contacts:roles',
        ], 'privacy:metadata:contacts');

        return $collection;
    }

    /**
     * Gets contexts containing user data for this plugin.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {format_selfstudy_contacts} c ON c.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :contextlevel
                   AND c.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Exports user data for the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_COURSE) {
                continue;
            }

            $contact = $DB->get_record('format_selfstudy_contacts', [
                'courseid' => (int)$context->instanceid,
                'userid' => $userid,
            ], '*', IGNORE_MISSING);
            if (!$contact) {
                continue;
            }

            writer::with_context($context)->export_data([
                get_string('pluginname', 'format_selfstudy'),
            ], (object)[
                'coursecontact' => $contact,
            ]);
        }
    }

    /**
     * Deletes all user data for the given context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }

        $DB->delete_records('format_selfstudy_contacts', ['courseid' => (int)$context->instanceid]);
    }

    /**
     * Deletes user data for the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_COURSE) {
                continue;
            }

            $DB->delete_records('format_selfstudy_contacts', [
                'courseid' => (int)$context->instanceid,
                'userid' => $userid,
            ]);
        }
    }
}
