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
 * Adds the course hero data of this format to the course backup.
 *
 * The learning path itself belongs to the shared library and is backed up by
 * backup_local_selfstudy_plugin. What is left here is what this format owns
 * alone: the hero contact persons and the hero image.
 */
class backup_format_selfstudy_plugin extends backup_format_plugin {

    /**
     * Adds the hero contacts and the hero image to the course backup.
     *
     * @return backup_plugin_element
     */
    public function define_course_plugin_structure() {
        // Only courses that really use this format carry the data.
        $plugin = $this->get_plugin_element(null, $this->get_format_condition(), 'selfstudy');

        $wrapper = new backup_nested_element($this->get_recommended_name());
        $contacts = new backup_nested_element('contacts');
        // The course id is left out - it is taken from the target course on restore.
        $contact = new backup_nested_element('contact', ['id'], [
            'userid', 'roles', 'sortorder', 'timecreated', 'timemodified',
        ]);

        $plugin->add_child($wrapper);
        $wrapper->add_child($contacts);
        $contacts->add_child($contact);

        $contact->set_source_table('format_selfstudy_contacts', ['courseid' => backup::VAR_COURSEID],
            'sortorder ASC, id ASC');

        // Contacts are course configuration, not user data, so they travel with
        // every backup. The annotation gets the referenced users into users.xml
        // whenever the backup includes users at all - without it the restore has
        // nothing to map them to and has to drop the contact.
        $contact->annotate_ids('user', 'userid');

        // The hero image lives in a file area of this format; nothing in core
        // picks up format file areas by itself.
        $wrapper->annotate_files('format_selfstudy', 'heroimage', null);

        return $plugin;
    }
}
