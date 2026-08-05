<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/course/format/lib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/filelib.php');

/**
 * Course format class for self-directed learning courses.
 */
class format_selfstudy extends \local_selfstudy\courseformat\base {

    /** Maximum number of course contacts shown in the hero. */
    private const MAX_HERO_CONTACTS = 10;

    /**
     * Adds format options elements to the course/section edit form.
     *
     * All course format options of this format are shared ones, so the headers
     * and the colour picker enhancement come from the shared base class. Only
     * the elements that belong to this format alone - course start page with
     * hero image, course contacts and section images - are built here.
     *
     * @param MoodleQuickForm $mform
     * @param bool $forsection
     * @return array
     */
    public function create_edit_form_elements(&$mform, $forsection = false) {
        $elements = parent::create_edit_form_elements($mform, $forsection);

        if ($forsection) {
            $sectionid = optional_param('id', 0, PARAM_INT);
            if ($sectionid) {
                $context = context_course::instance($this->courseid);
                $draftitemid = file_get_submitted_draft_itemid('selfstudysectionimage_filemanager');
                $fileoptions = $this->get_section_image_filemanager_options();
                file_prepare_draft_area($draftitemid, $context->id, 'local_selfstudy', 'sectionimage',
                    $sectionid, $fileoptions);
                $mform->addElement('filemanager', 'selfstudysectionimage_filemanager',
                    get_string('sectionimage', 'format_selfstudy'), null, $fileoptions);
                $mform->addHelpButton('selfstudysectionimage_filemanager', 'sectionimage', 'format_selfstudy');
                $mform->setDefault('selfstudysectionimage_filemanager', $draftitemid);
            }
        }

        if (!$forsection && !empty($this->courseid)) {
            $context = context_course::instance($this->courseid);
            $draftitemid = file_get_submitted_draft_itemid('selfstudyheroimage_filemanager');
            $fileoptions = $this->get_hero_image_filemanager_options();
            file_prepare_draft_area($draftitemid, $context->id, 'format_selfstudy', 'heroimage', 0, $fileoptions);
            $startheader = $mform->addElement('header', 'selfstudycourseformatstart',
                get_string('courseformatsettingsstart', 'format_selfstudy'));
            $mform->setExpanded('selfstudycourseformatstart', false);
            $startintro = $mform->addElement('static', 'selfstudycourseformatstart_intro', '',
                get_string('courseformatsettingsstart_desc', 'format_selfstudy'));
            $heroimage = $mform->addElement('filemanager', 'selfstudyheroimage_filemanager',
                get_string('courseheroimage', 'format_selfstudy'), null, $fileoptions);
            $mform->addHelpButton('selfstudyheroimage_filemanager', 'courseheroimage', 'format_selfstudy');
            $mform->setDefault('selfstudyheroimage_filemanager', $draftitemid);
            $heroimagehint = $mform->addElement('static', 'selfstudyheroimage_hint', '',
                get_string('courseheroimage_hint', 'format_selfstudy'));
            $contactsheader = $mform->addElement('header', 'selfstudycourseformatcontacts',
                get_string('coursecontacts', 'format_selfstudy'));
            $mform->setExpanded('selfstudycourseformatcontacts', false);
            $contactintro = $mform->addElement('static', 'selfstudycoursecontacts_intro', '',
                get_string('coursecontactsintro', 'format_selfstudy'));

            $contactelements = [$contactsheader, $contactintro];
            $contactusers = $this->get_course_contact_user_options($context);
            unset($contactusers[0]);
            $contacts = format_selfstudy_get_course_contacts((int)$this->courseid);
            $authorids = [];
            $supportids = [];
            foreach ($contacts as $contact) {
                $userid = (int)$contact->userid;
                $roles = explode(',', (string)$contact->roles);
                if (in_array('author', $roles, true)) {
                    $authorids[] = $userid;
                }
                if (in_array('support', $roles, true)) {
                    $supportids[] = $userid;
                }
            }

            $authorfield = $mform->addElement('autocomplete', 'selfstudycontactauthors',
                get_string('coursecontactroleauthor', 'format_selfstudy'), $contactusers, ['multiple' => true]);
            $mform->setType('selfstudycontactauthors', PARAM_INT);
            $mform->setDefault('selfstudycontactauthors', $authorids);
            $contactelements[] = $authorfield;

            $supportfield = $mform->addElement('autocomplete', 'selfstudycontactsupport',
                get_string('coursecontactrolesupport', 'format_selfstudy'), $contactusers, ['multiple' => true]);
            $mform->setType('selfstudycontactsupport', PARAM_INT);
            $mform->setDefault('selfstudycontactsupport', $supportids);
            $contactelements[] = $supportfield;

            $sharedelements = $this->get_shared_edit_form_elements($mform);

            $designcolorelements = [];
            $designnavigationelements = [];
            $generalelements = [];
            $shareddesignnames = $this->get_shared_design_element_names();
            $designcolornames = $shareddesignnames['colors'];
            $designnavigationnames = $shareddesignnames['navigation'];
            foreach ($elements as $element) {
                if (in_array($element->getName(), $designcolornames, true)) {
                    $designcolorelements[] = $element;
                } else if (in_array($element->getName(), $designnavigationnames, true)) {
                    $designnavigationelements[] = $element;
                } else {
                    $generalelements[] = $element;
                }
            }

            $elements = array_merge(
                [$startheader, $startintro, $heroimage, $heroimagehint],
                $contactelements,
                [$sharedelements['generalheader'], $sharedelements['generalintro']],
                $generalelements,
                [$sharedelements['colorsheader'], $sharedelements['colorsintro']],
                $designcolorelements,
                [$sharedelements['navigationheader'], $sharedelements['navigationintro']],
                $designnavigationelements
            );
        }

        if (!$forsection) {
            $this->apply_shared_color_picker_attributes($mform);
        }

        return $elements;
    }

    /**
     * Updates section options and persists the section image.
     *
     * @param stdClass|array $data
     * @return bool
     */
    public function update_section_format_options($data) {
        $changed = parent::update_section_format_options($data);
        $dataobject = (object)$data;
        if (!empty($dataobject->id) && !empty($dataobject->selfstudysectionimage_filemanager)) {
            $context = context_course::instance($this->courseid);
            file_save_draft_area_files($dataobject->selfstudysectionimage_filemanager, $context->id,
                'local_selfstudy', 'sectionimage', (int)$dataobject->id,
                $this->get_section_image_filemanager_options());
        }

        return $changed;
    }

    /**
     * Updates format options and persists the course hero image.
     *
     * @param stdClass|array $data
     * @param stdClass|null $oldcourse
     * @return bool
     */
    public function update_course_format_options($data, $oldcourse = null) {
        $changed = parent::update_course_format_options($data, $oldcourse);
        $dataobject = (object)$data;
        if (!empty($this->courseid) && !empty($dataobject->selfstudyheroimage_filemanager)) {
            $context = context_course::instance($this->courseid);
            file_save_draft_area_files($dataobject->selfstudyheroimage_filemanager, $context->id,
                'format_selfstudy', 'heroimage', 0, $this->get_hero_image_filemanager_options());
        }
        $this->save_course_contacts_from_form($dataobject);

        return $changed;
    }

    /**
     * Returns upload constraints for the course hero image.
     *
     * @return array
     */
    protected function get_hero_image_filemanager_options(): array {
        return [
            'subdirs' => 0,
            'maxfiles' => 1,
            'accepted_types' => ['web_image'],
            'return_types' => FILE_INTERNAL,
        ];
    }

    /**
     * Returns upload constraints for section overview images.
     *
     * @return array
     */
    protected function get_section_image_filemanager_options(): array {
        return [
            'subdirs' => 0,
            'maxfiles' => 1,
            'accepted_types' => ['web_image'],
            'return_types' => FILE_INTERNAL,
        ];
    }

    /**
     * Returns enrolled users for the course contact selector.
     *
     * @param context_course $context
     * @return array
     */
    protected function get_course_contact_user_options(context_course $context): array {
        $options = [0 => get_string('choosedots')];
        $users = get_enrolled_users($context, '', 0, 'u.*', 'u.lastname ASC, u.firstname ASC', 0, 0, true);
        foreach ($users as $user) {
            if (isguestuser($user)) {
                continue;
            }
            $options[(int)$user->id] = fullname($user);
        }

        return $options;
    }

    /**
     * Normalises submitted contact user ids from an autocomplete element.
     *
     * @param mixed $value Submitted value.
     * @param context_course $context Course context.
     * @return int[]
     */
    protected function normalise_course_contact_userids($value, context_course $context): array {
        if ($value === null || $value === '') {
            return [];
        }

        $values = is_array($value) ? $value : [$value];
        $userids = [];
        foreach ($values as $rawuserid) {
            $userid = (int)$rawuserid;
            if (!$userid || isset($userids[$userid]) || !is_enrolled($context, $userid, '', true)) {
                continue;
            }
            $userids[$userid] = $userid;
        }

        return array_values($userids);
    }

    /**
     * Persists the course hero contact persons from the course edit form.
     *
     * @param stdClass $data
     */
    protected function save_course_contacts_from_form(stdClass $data): void {
        global $DB;

        if (empty($this->courseid) || !format_selfstudy_contacts_table_exists()) {
            return;
        }

        // Only data that really carries the contact selectors may change them.
        // update_course_format_options() is also called with a plain course
        // record - the course restore does that - and such a call must leave
        // the stored contacts alone instead of wiping them.
        if (!property_exists($data, 'selfstudycontactauthors') &&
                !property_exists($data, 'selfstudycontactsupport')) {
            return;
        }

        $context = context_course::instance($this->courseid);
        $now = time();
        $records = [];
        $rolemap = [];
        $sortorder = [];
        $submittedroles = [
            'author' => $data->selfstudycontactauthors ?? [],
            'support' => $data->selfstudycontactsupport ?? [],
        ];

        foreach ($submittedroles as $role => $submitteduserids) {
            foreach ($this->normalise_course_contact_userids($submitteduserids, $context) as $userid) {
                if (!isset($rolemap[$userid])) {
                    $rolemap[$userid] = [];
                    $sortorder[] = $userid;
                }
                if (!in_array($role, $rolemap[$userid], true)) {
                    $rolemap[$userid][] = $role;
                }
            }
        }

        foreach ($sortorder as $userid) {
            if (count($records) >= self::MAX_HERO_CONTACTS) {
                break;
            }

            $records[] = (object)[
                'courseid' => (int)$this->courseid,
                'userid' => $userid,
                'roles' => implode(',', $rolemap[$userid]),
                'sortorder' => count($records),
                'timecreated' => $now,
                'timemodified' => $now,
            ];
        }

        $DB->delete_records('format_selfstudy_contacts', ['courseid' => (int)$this->courseid]);
        foreach ($records as $record) {
            $DB->insert_record('format_selfstudy_contacts', $record);
        }
    }

}

/**
 * Serves files from the selfstudy course format file areas.
 *
 * @param stdClass $course
 * @param stdClass|null $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool
 */
function format_selfstudy_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload,
        array $options = []): bool {
    if ($context->contextlevel !== CONTEXT_COURSE || $filearea !== 'heroimage') {
        return false;
    }
    // NICHT has_capability('moodle/course:view'): die Berechtigung heisst "Kurse
    // ansehen, ohne teilzunehmen" und liegt bei Manager/innen. Eingeschriebene
    // Teilnehmende und Trainer/innen haben sie nicht - fuer sie blieb das Hero-Bild
    // leer. Der Kern prueft in diesem Zweig von file_pluginfile() gar nichts.
    require_login($course, false, $cm);

    $filename = array_pop($args);
    if (!$filename || $filename === '.') {
        return false;
    }

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'format_selfstudy', $filearea, 0, '/', $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    \core\session\manager::write_close();
    send_stored_file($file, 0, 0, $forcedownload, $options);

    return true;
}

/**
 * Returns the configured course hero image URL.
 *
 * @param stdClass $course
 * @return moodle_url|null
 */
function format_selfstudy_get_hero_image_url(stdClass $course): ?moodle_url {
    $context = context_course::instance($course->id);
    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'format_selfstudy', 'heroimage', 0, 'sortorder, id', false);
    $file = reset($files);
    if (!$file) {
        return null;
    }

    return moodle_url::make_pluginfile_url($context->id, 'format_selfstudy', 'heroimage', 0, '/',
        $file->get_filename(), false);
}

/**
 * Returns whether the hero contact table is available.
 *
 * @return bool
 */
function format_selfstudy_contacts_table_exists(): bool {
    global $DB;

    return $DB->get_manager()->table_exists('format_selfstudy_contacts');
}

/**
 * Returns configured course hero contacts with user records.
 *
 * @param int $courseid
 * @return stdClass[]
 */
function format_selfstudy_get_course_contacts(int $courseid): array {
    global $DB;

    if (!format_selfstudy_contacts_table_exists()) {
        return [];
    }

    $sql = "SELECT c.id AS contactid, c.courseid, c.userid, c.roles, c.sortorder,
                   u.*
              FROM {format_selfstudy_contacts} c
              JOIN {user} u ON u.id = c.userid
             WHERE c.courseid = :courseid
               AND u.deleted = 0
          ORDER BY c.sortorder ASC, c.id ASC";

    return array_values($DB->get_records_sql($sql, ['courseid' => $courseid], 0, 10));
}

/**
 * Returns available display role labels for hero contacts.
 *
 * @return array
 */
function format_selfstudy_get_course_contact_role_labels(): array {
    return [
        'author' => get_string('coursecontactroleauthor', 'format_selfstudy'),
        'support' => get_string('coursecontactrolesupport', 'format_selfstudy'),
    ];
}
