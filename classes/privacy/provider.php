<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for the self-directed learning course format.
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
        $collection->add_database_table('format_selfstudy_choices', [
            'courseid' => 'privacy:metadata:choices:courseid',
            'userid' => 'privacy:metadata:choices:userid',
            'pathid' => 'privacy:metadata:choices:pathid',
            'timecreated' => 'privacy:metadata:choices:timecreated',
            'timemodified' => 'privacy:metadata:choices:timemodified',
        ], 'privacy:metadata:choices');

        $collection->add_database_table('format_selfstudy_milestones', [
            'itemid' => 'privacy:metadata:milestones:itemid',
            'userid' => 'privacy:metadata:milestones:userid',
            'completed' => 'privacy:metadata:milestones:completed',
            'timecompleted' => 'privacy:metadata:milestones:timecompleted',
            'timemodified' => 'privacy:metadata:milestones:timemodified',
        ], 'privacy:metadata:milestones');

        $collection->add_database_table('format_selfstudy_paths', [
            'courseid' => 'privacy:metadata:personalpaths:courseid',
            'userid' => 'privacy:metadata:personalpaths:userid',
            'name' => 'privacy:metadata:personalpaths:name',
            'timecreated' => 'privacy:metadata:personalpaths:timecreated',
            'timemodified' => 'privacy:metadata:personalpaths:timemodified',
        ], 'privacy:metadata:personalpaths');

        $collection->add_database_table('format_selfstudy_experiences', [
            'courseid' => 'privacy:metadata:experiences:courseid',
            'component' => 'privacy:metadata:experiences:component',
            'enabled' => 'privacy:metadata:experiences:enabled',
            'configjson' => 'privacy:metadata:experiences:configjson',
        ], 'privacy:metadata:experiences');

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
        $params = [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ];

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {format_selfstudy_choices} c ON c.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :contextlevel
                   AND c.userid = :userid";
        $contextlist->add_from_sql($sql, $params);

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {format_selfstudy_paths} p ON p.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :contextlevel
                   AND p.userid = :userid";
        $contextlist->add_from_sql($sql, $params);

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {format_selfstudy_paths} p ON p.courseid = ctx.instanceid
                  JOIN {format_selfstudy_path_items} i ON i.pathid = p.id
                  JOIN {format_selfstudy_milestones} m ON m.itemid = i.id
                 WHERE ctx.contextlevel = :contextlevel
                   AND m.userid = :userid";
        $contextlist->add_from_sql($sql, $params);

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {format_selfstudy_contacts} c ON c.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :contextlevel
                   AND c.userid = :userid";
        $contextlist->add_from_sql($sql, $params);

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

            $courseid = (int)$context->instanceid;
            $choice = $DB->get_record('format_selfstudy_choices', [
                'courseid' => $courseid,
                'userid' => $userid,
            ], '*', IGNORE_MISSING);

            $milestonesql = "SELECT m.*, i.title, i.pathid
                               FROM {format_selfstudy_milestones} m
                               JOIN {format_selfstudy_path_items} i ON i.id = m.itemid
                               JOIN {format_selfstudy_paths} p ON p.id = i.pathid
                              WHERE p.courseid = :courseid
                                AND m.userid = :userid
                           ORDER BY i.pathid ASC, i.sortorder ASC, i.id ASC";
            $milestones = array_values($DB->get_records_sql($milestonesql, [
                'courseid' => $courseid,
                'userid' => $userid,
            ]));
            $personalpath = $DB->get_record('format_selfstudy_paths', [
                'courseid' => $courseid,
                'userid' => $userid,
            ], '*', IGNORE_MISSING);
            $contact = $DB->get_record('format_selfstudy_contacts', [
                'courseid' => $courseid,
                'userid' => $userid,
            ], '*', IGNORE_MISSING);

            if (!$choice && !$milestones && !$personalpath && !$contact) {
                continue;
            }

            writer::with_context($context)->export_data([
                get_string('pluginname', 'format_selfstudy'),
                get_string('privacy:export:pathdata', 'format_selfstudy'),
            ], (object)[
                'activepath' => $choice,
                'milestones' => $milestones,
                'personalpath' => $personalpath,
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

        $courseid = (int)$context->instanceid;
        $itemids = self::get_course_path_itemids($courseid);
        if ($itemids) {
            [$insql, $params] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'itemid');
            $DB->delete_records_select('format_selfstudy_milestones', "itemid $insql", $params);
        }

        $DB->delete_records('format_selfstudy_choices', ['courseid' => $courseid]);
        $DB->delete_records('format_selfstudy_contacts', ['courseid' => $courseid]);
        self::delete_personal_paths_select('courseid = :courseid', ['courseid' => $courseid]);
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

            $courseid = (int)$context->instanceid;
            $itemids = self::get_course_path_itemids($courseid);
            if ($itemids) {
                [$insql, $params] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'itemid');
                $params['userid'] = $userid;
                $DB->delete_records_select('format_selfstudy_milestones',
                    "userid = :userid AND itemid $insql", $params);
            }

            $DB->delete_records('format_selfstudy_choices', [
                'courseid' => $courseid,
                'userid' => $userid,
            ]);
            $DB->delete_records('format_selfstudy_contacts', [
                'courseid' => $courseid,
                'userid' => $userid,
            ]);
            self::delete_personal_paths_select('courseid = :courseid AND userid = :userid', [
                'courseid' => $courseid,
                'userid' => $userid,
            ]);
        }
    }

    /**
     * Deletes personal paths and their dependent records.
     *
     * @param string $select
     * @param array $params
     */
    private static function delete_personal_paths_select(string $select, array $params): void {
        global $DB;

        $pathids = $DB->get_fieldset_select('format_selfstudy_paths', 'id', $select . ' AND userid <> 0', $params);
        if (!$pathids) {
            return;
        }

        [$pathsql, $pathparams] = $DB->get_in_or_equal($pathids, SQL_PARAMS_NAMED, 'pathid');
        $itemids = $DB->get_fieldset_select('format_selfstudy_path_items', 'id', "pathid $pathsql", $pathparams);
        if ($itemids) {
            [$itemsql, $itemparams] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'itemid');
            $DB->delete_records_select('format_selfstudy_milestones', "itemid $itemsql", $itemparams);
        }
        $DB->delete_records_select('format_selfstudy_choices', "pathid $pathsql", $pathparams);
        $DB->delete_records_select('format_selfstudy_path_items', "pathid $pathsql", $pathparams);
        $DB->delete_records_select('format_selfstudy_paths', "id $pathsql", $pathparams);
    }

    /**
     * Returns all path item ids for a course.
     *
     * @param int $courseid
     * @return int[]
     */
    private static function get_course_path_itemids(int $courseid): array {
        global $DB;

        $sql = "SELECT i.id
                  FROM {format_selfstudy_path_items} i
                  JOIN {format_selfstudy_paths} p ON p.id = i.pathid
                 WHERE p.courseid = :courseid";

        return array_map('intval', $DB->get_fieldset_sql($sql, ['courseid' => $courseid]));
    }
}
