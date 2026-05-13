<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Backup support for the selfstudy course format.
 *
 * @package   format_selfstudy
 * @category  backup
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Defines the plugin tables that belong to a selfstudy course.
 */
class backup_format_selfstudy_plugin extends backup_format_plugin {

    /**
     * Adds selfstudy path data to the course backup.
     *
     * @return backup_plugin_element
     */
    public function define_course_plugin_structure() {
        $plugin = $this->get_plugin_element(null, $this->get_format_condition(), 'selfstudy');

        $wrapper = new backup_nested_element($this->get_recommended_name());
        $paths = new backup_nested_element('paths');
        $path = new backup_nested_element('path', ['id'], [
            'courseid', 'userid', 'name', 'description', 'descriptionformat', 'imageurl', 'icon',
            'sortorder', 'enabled', 'timecreated', 'timemodified',
        ]);
        $items = new backup_nested_element('items');
        $item = new backup_nested_element('item', ['id'], [
            'pathid', 'parentid', 'itemtype', 'cmid', 'sectionid', 'title', 'description',
            'descriptionformat', 'availabilitymode', 'configdata', 'sortorder', 'timecreated', 'timemodified',
        ]);
        $snapshots = new backup_nested_element('snapshots');
        $snapshot = new backup_nested_element('snapshot', ['id'], [
            'pathid', 'courseid', 'userid', 'schema', 'snapshotjson', 'sourcehash', 'timecreated', 'timemodified',
        ]);
        $revisions = new backup_nested_element('revisions');
        $revision = new backup_nested_element('revision', ['id'], [
            'pathid', 'courseid', 'userid', 'revision', 'schema', 'snapshotjson', 'sourcehash',
            'publishedby', 'timepublished', 'status', 'active', 'timecreated', 'timemodified',
        ]);
        $choices = new backup_nested_element('choices');
        $choice = new backup_nested_element('choice', ['id'], [
            'courseid', 'userid', 'pathid', 'timecreated', 'timemodified',
        ]);
        $milestones = new backup_nested_element('milestones');
        $milestone = new backup_nested_element('milestone', ['id'], [
            'itemid', 'userid', 'completed', 'timecompleted', 'timemodified',
        ]);
        $cmgoals = new backup_nested_element('cmgoals');
        $cmgoal = new backup_nested_element('cmgoal', ['id'], [
            'cmid', 'learninggoal', 'durationminutes', 'timecreated', 'timemodified',
        ]);

        $plugin->add_child($wrapper);
        $wrapper->add_child($paths);
        $paths->add_child($path);
        $path->add_child($items);
        $items->add_child($item);
        $path->add_child($snapshots);
        $snapshots->add_child($snapshot);
        $path->add_child($revisions);
        $revisions->add_child($revision);
        $wrapper->add_child($choices);
        $choices->add_child($choice);
        $wrapper->add_child($milestones);
        $milestones->add_child($milestone);
        $wrapper->add_child($cmgoals);
        $cmgoals->add_child($cmgoal);

        $usersql = $this->task->get_setting_value('users') ? '' : ' AND userid = 0';
        $path->set_source_sql(
            'SELECT *
               FROM {format_selfstudy_paths}
              WHERE courseid = :courseid' . $usersql . '
           ORDER BY userid ASC, sortorder ASC, id ASC',
            ['courseid' => backup::VAR_COURSEID]
        );
        $item->set_source_table('format_selfstudy_path_items', ['pathid' => backup::VAR_PARENTID],
            'parentid ASC, sortorder ASC, id ASC');
        $snapshot->set_source_table('format_selfstudy_snapshots', ['pathid' => backup::VAR_PARENTID]);
        $revision->set_source_table('format_selfstudy_revisions', ['pathid' => backup::VAR_PARENTID],
            'revision ASC, id ASC');

        if ($this->task->get_setting_value('users')) {
            $choice->set_source_table('format_selfstudy_choices', ['courseid' => backup::VAR_COURSEID],
                'userid ASC, id ASC');
            $milestone->set_source_sql(
                'SELECT m.*
                   FROM {format_selfstudy_milestones} m
                   JOIN {format_selfstudy_path_items} i ON i.id = m.itemid
                   JOIN {format_selfstudy_paths} p ON p.id = i.pathid
                  WHERE p.courseid = :courseid
               ORDER BY m.itemid ASC, m.userid ASC',
                ['courseid' => backup::VAR_COURSEID]
            );
        }

        $cmgoal->set_source_sql(
            'SELECT g.id, g.cmid, g.learninggoal, g.durationminutes, g.timecreated, g.timemodified
               FROM {format_selfstudy_cmgoals} g
               JOIN {course_modules} cm ON cm.id = g.cmid
              WHERE cm.course = :courseid
           ORDER BY g.cmid ASC',
            ['courseid' => backup::VAR_COURSEID]
        );

        return $plugin;
    }
}
