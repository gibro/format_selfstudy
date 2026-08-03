<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Restore support for the selfstudy course format.
 *
 * @package   format_selfstudy
 * @category  backup
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/format/selfstudy/lib.php');

/**
 * Restores the course hero contacts and the hero image of this format.
 */
class restore_format_selfstudy_plugin extends restore_format_plugin {

    /** @var int|null Offset added to restored sort orders, resolved on first use. */
    private $sortorderoffset = null;

    /**
     * Defines the restore paths of this format.
     *
     * @return restore_path_element[]
     */
    public function define_course_plugin_structure() {
        return [
            new restore_path_element('format_selfstudy_contact', $this->get_pathfor('/contacts/contact')),
        ];
    }

    /**
     * Restores one course hero contact.
     *
     * A contact only makes sense when its user came along with the backup, so a
     * contact whose user cannot be mapped - a backup without users, or a user
     * that no longer exists - is dropped instead of pointing somewhere random.
     *
     * @param array $data
     */
    public function process_format_selfstudy_contact(array $data): void {
        global $DB;

        if (!format_selfstudy_contacts_table_exists()) {
            return;
        }

        $data = (object)$data;
        $olduserid = (int)($data->userid ?? 0);
        $data->courseid = $this->task->get_courseid();
        $data->userid = $olduserid ? (int)$this->get_mappingid('user', $olduserid, 0) : 0;
        if (empty($data->userid)) {
            return;
        }

        $data->roles = $this->clean_roles((string)($data->roles ?? ''));
        if ($data->roles === '') {
            return;
        }

        // The table holds one record per course and user. When restoring into a
        // course that already lists this person, the existing entry stays.
        if ($DB->record_exists('format_selfstudy_contacts', [
                'courseid' => $data->courseid,
                'userid' => $data->userid,
            ])) {
            return;
        }

        $now = time();
        $data->sortorder = (int)($data->sortorder ?? 0) + $this->get_sortorder_offset();
        $data->timecreated = (int)($data->timecreated ?? 0) ?: $now;
        $data->timemodified = $now;
        unset($data->id);

        $DB->insert_record('format_selfstudy_contacts', $data);
    }

    /**
     * Restores the course hero image once the course structure has been read.
     */
    public function after_execute_course(): void {
        $context = context_course::instance($this->task->get_courseid());
        $fs = get_file_storage();

        // A hero image already in place belongs to the target course and wins -
        // otherwise a restore into an existing course could push it aside.
        if ($fs->get_area_files($context->id, 'format_selfstudy', 'heroimage', 0, 'sortorder, id', false)) {
            return;
        }

        $this->add_related_files('format_selfstudy', 'heroimage', null);
    }

    /**
     * Keeps only the display roles this format knows.
     *
     * @param string $roles Comma separated role keys from the backup.
     * @return string
     */
    private function clean_roles(string $roles): string {
        $known = array_keys(format_selfstudy_get_course_contact_role_labels());
        $cleaned = [];
        foreach (explode(',', $roles) as $role) {
            $role = trim($role);
            if ($role !== '' && in_array($role, $known, true) && !in_array($role, $cleaned, true)) {
                $cleaned[] = $role;
            }
        }

        return implode(',', $cleaned);
    }

    /**
     * Returns the sort order offset that keeps restored contacts behind existing ones.
     *
     * @return int
     */
    private function get_sortorder_offset(): int {
        global $DB;

        if ($this->sortorderoffset === null) {
            $max = $DB->get_field_sql('SELECT MAX(sortorder) FROM {format_selfstudy_contacts} WHERE courseid = ?',
                [$this->task->get_courseid()]);
            $this->sortorderoffset = ($max === null || $max === false) ? 0 : (int)$max + 1;
        }

        return $this->sortorderoffset;
    }
}
