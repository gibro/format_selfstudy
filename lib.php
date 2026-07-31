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
     * Course level format options.
     *
     * @param bool $foreditform
     * @return array
     */
    public function course_format_options($foreditform = false): array {
        $options = [
            'defaultview' => [
                'default' => 'dashboard',
                'type' => PARAM_ALPHA,
            ],
            'enabledashboard' => [
                'default' => 1,
                'type' => PARAM_BOOL,
            ],
            'enableavatar' => [
                'default' => 1,
                'type' => PARAM_BOOL,
            ],
            'enableactivitynavigation' => [
                'default' => 1,
                'type' => PARAM_BOOL,
            ],
            'shownavprevious' => [
                'default' => 1,
                'type' => PARAM_BOOL,
            ],
            'shownavmap' => [
                'default' => 1,
                'type' => PARAM_BOOL,
            ],
            'shownavnext' => [
                'default' => 1,
                'type' => PARAM_BOOL,
            ],
            'showactivitystatus' => [
                'default' => 1,
                'type' => PARAM_BOOL,
            ],
            'useactivitymetadata' => [
                'default' => 1,
                'type' => PARAM_BOOL,
            ],
            'showlockedactivities' => [
                'default' => 1,
                'type' => PARAM_BOOL,
            ],
            'allowpersonalpaths' => [
                'default' => 0,
                'type' => PARAM_BOOL,
            ],
            'pathpointcolor' => [
                'default' => '#6f1ab1',
                'type' => PARAM_TEXT,
            ],
            'nextbuttoncolor' => [
                'default' => '#6f1ab1',
                'type' => PARAM_TEXT,
            ],
            'previousbuttonlabel' => [
                'default' => get_string('previousbuttonlabel_default', 'format_selfstudy'),
                'type' => PARAM_TEXT,
            ],
            'mapbuttonlabel' => [
                'default' => get_string('mapbuttonlabel_default', 'format_selfstudy'),
                'type' => PARAM_TEXT,
            ],
            'nextbuttonlabel' => [
                'default' => get_string('nextbuttonlabel_default', 'format_selfstudy'),
                'type' => PARAM_TEXT,
            ],
            'customnavbuttonlabel' => [
                'default' => '',
                'type' => PARAM_TEXT,
            ],
            'customnavbuttonurl' => [
                'default' => '',
                'type' => PARAM_URL,
            ],
        ];

        if ($foreditform) {
            unset($options['allowpersonalpaths']);
            unset($options['enableavatar']);
            unset($options['defaultview']);
            unset($options['enabledashboard']);
            unset($options['shownavmap']);
            unset($options['shownavnext']);
            unset($options['mapbuttonlabel']);

            $options['enableactivitynavigation']['label'] = get_string('enableactivitynavigation', 'format_selfstudy');
            $options['enableactivitynavigation']['help'] = 'enableactivitynavigation';
            $options['enableactivitynavigation']['help_component'] = 'format_selfstudy';
            $options['enableactivitynavigation']['element_type'] = 'advcheckbox';

            $options['shownavprevious']['label'] = get_string('shownavprevious', 'format_selfstudy');
            $options['shownavprevious']['element_type'] = 'advcheckbox';

            $options['showactivitystatus']['label'] = get_string('showactivitystatus', 'format_selfstudy');
            $options['showactivitystatus']['help'] = 'showactivitystatus';
            $options['showactivitystatus']['help_component'] = 'format_selfstudy';
            $options['showactivitystatus']['element_type'] = 'advcheckbox';

            $options['useactivitymetadata']['label'] = get_string('useactivitymetadata', 'format_selfstudy');
            $options['useactivitymetadata']['help'] = 'useactivitymetadata';
            $options['useactivitymetadata']['help_component'] = 'format_selfstudy';
            $options['useactivitymetadata']['element_type'] = 'advcheckbox';

            $options['showlockedactivities']['label'] = get_string('showlockedactivities', 'format_selfstudy');
            $options['showlockedactivities']['help'] = 'showlockedactivities';
            $options['showlockedactivities']['help_component'] = 'format_selfstudy';
            $options['showlockedactivities']['element_type'] = 'advcheckbox';

            $options['pathpointcolor']['label'] = get_string('pathpointcolor', 'format_selfstudy');
            $options['pathpointcolor']['help'] = 'pathpointcolor';
            $options['pathpointcolor']['help_component'] = 'format_selfstudy';
            $options['pathpointcolor']['element_type'] = 'text';
            $options['pathpointcolor']['element_attributes'] = [[
                'maxlength' => 7,
                'pattern' => '#[0-9a-fA-F]{6}',
                'placeholder' => '#6f1ab1',
                'class' => 'format-selfstudy-colorpicker',
                'style' => 'display:inline-block;width:3.5rem;height:2.35rem;padding:.15rem;cursor:pointer;vertical-align:middle;',
            ]];

            $options['nextbuttoncolor']['label'] = get_string('nextbuttoncolor', 'format_selfstudy');
            $options['nextbuttoncolor']['help'] = 'nextbuttoncolor';
            $options['nextbuttoncolor']['help_component'] = 'format_selfstudy';
            $options['nextbuttoncolor']['element_type'] = 'text';
            $options['nextbuttoncolor']['element_attributes'] = [[
                'maxlength' => 7,
                'pattern' => '#[0-9a-fA-F]{6}',
                'placeholder' => '#6f1ab1',
                'class' => 'format-selfstudy-colorpicker',
                'style' => 'display:inline-block;width:3.5rem;height:2.35rem;padding:.15rem;cursor:pointer;vertical-align:middle;',
            ]];

            $options['previousbuttonlabel']['label'] = get_string('previousbuttonlabel', 'format_selfstudy');
            $options['previousbuttonlabel']['element_type'] = 'text';

            $options['nextbuttonlabel']['label'] = get_string('nextbuttonlabel', 'format_selfstudy');
            $options['nextbuttonlabel']['element_type'] = 'text';

            $options['customnavbuttonlabel']['label'] = get_string('customnavbuttonlabel', 'format_selfstudy');
            $options['customnavbuttonlabel']['element_type'] = 'text';

            $options['customnavbuttonurl']['label'] = get_string('customnavbuttonurl', 'format_selfstudy');
            $options['customnavbuttonurl']['element_type'] = 'text';
        }

        return $options;
    }

    /**
     * Adds format options elements to the course/section edit form.
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

            $generalheader = $mform->addElement('header', 'selfstudycourseformatgeneral',
                get_string('courseformatsettingsgeneral', 'format_selfstudy'));
            $mform->setExpanded('selfstudycourseformatgeneral', false);
            $generalintro = $mform->addElement('static', 'selfstudycourseformatgeneral_intro', '',
                get_string('courseformatsettingsgeneral_desc', 'format_selfstudy'));
            $colorsheader = $mform->addElement('header', 'selfstudycourseformatdesigncolors',
                get_string('courseformatsettingsdesigncolors', 'format_selfstudy'));
            $mform->setExpanded('selfstudycourseformatdesigncolors', false);
            $designcolorsintro = $mform->addElement('static', 'selfstudycourseformatdesign_colors', '',
                html_writer::tag('style',
                    '.format-selfstudy-colorpicker::-webkit-color-swatch-wrapper{padding:0;}' .
                    '.format-selfstudy-colorpicker::-webkit-color-swatch{border:0;border-radius:.2rem;}' .
                    '.format-selfstudy-colorpicker::-moz-color-swatch{border:0;border-radius:.2rem;}'
                ) .
                html_writer::tag('script',
                    'document.addEventListener("DOMContentLoaded",function(){' .
                    '["pathpointcolor","nextbuttoncolor"].forEach(function(name){' .
                    'var input=document.querySelector("[name="+JSON.stringify(name)+"]");' .
                    'if(!input){return;}' .
                    'input.setAttribute("type","color");' .
                    'input.className="format-selfstudy-colorpicker";' .
                    'input.style.cssText="display:inline-block;width:3.5rem;height:2.35rem;padding:.15rem;cursor:pointer;vertical-align:middle;";' .
                    'var row=input.closest(".fitem");' .
                    'if(!row){return;}' .
                    'row.classList.add("row","mb-3");' .
                    'row.classList.remove("fitem_fcolor");' .
                    'var label=row.querySelector(".col-form-label,.fitemtitle");' .
                    'var element=row.querySelector(".felement");' .
                    'if(label){label.classList.add("col-md-3","col-form-label","d-flex","pb-0","pe-md-0");}' .
                    'if(element){element.classList.add("col-md-9","d-flex","flex-wrap","align-items-start");element.classList.remove("col-md-3");}' .
                    '});' .
                    '});'
                ) .
                html_writer::div(get_string('courseformatsettingsdesigncolors_desc', 'format_selfstudy'),
                    'format-selfstudy-settings-help'));
            $navheader = $mform->addElement('header', 'selfstudycourseformatnavigation',
                get_string('courseformatsettingsdesignnavigation', 'format_selfstudy'));
            $mform->setExpanded('selfstudycourseformatnavigation', false);
            $designnavigationintro = $mform->addElement('static', 'selfstudycourseformatdesign_navigation', '',
                html_writer::div(get_string('courseformatsettingsdesignnavigation_desc', 'format_selfstudy'),
                    'format-selfstudy-settings-help'));

            $designcolorelements = [];
            $designnavigationelements = [];
            $generalelements = [];
            $designcolornames = [
                'pathpointcolor',
                'nextbuttoncolor',
            ];
            $designnavigationnames = [
                'previousbuttonlabel',
                'nextbuttonlabel',
                'customnavbuttonlabel',
                'customnavbuttonurl',
            ];
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
                [$generalheader, $generalintro],
                $generalelements,
                [$colorsheader, $designcolorsintro],
                $designcolorelements,
                [$navheader, $designnavigationintro],
                $designnavigationelements
            );
        }

        if (!$forsection && $mform->elementExists('pathpointcolor')) {
            $element = $mform->getElement('pathpointcolor');
            $element->updateAttributes([
                'type' => 'color',
                'class' => 'format-selfstudy-colorpicker',
                'style' => 'display:inline-block;width:3.5rem;height:2.35rem;padding:.15rem;cursor:pointer;vertical-align:middle;',
                'title' => get_string('pathpointcolor', 'format_selfstudy'),
            ]);
        }
        if (!$forsection && $mform->elementExists('nextbuttoncolor')) {
            $element = $mform->getElement('nextbuttoncolor');
            $element->updateAttributes([
                'type' => 'color',
                'class' => 'format-selfstudy-colorpicker',
                'style' => 'display:inline-block;width:3.5rem;height:2.35rem;padding:.15rem;cursor:pointer;vertical-align:middle;',
                'title' => get_string('nextbuttoncolor', 'format_selfstudy'),
            ]);
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

    /**
     * Validates format options for the course.
     *
     * @param array $data
     * @return array
     */
    public function validate_course_format_options(array $data): array {
        $data = parent::validate_course_format_options($data);
        if (($data['defaultview'] ?? '') === 'map') {
            $data['defaultview'] = 'dashboard';
        }
        if (($data['defaultview'] ?? '') === 'list') {
            $data['defaultview'] = 'dashboard';
        }
        if (isset($data['pathpointcolor'])) {
            $data['pathpointcolor'] = \local_selfstudy\helper::normalise_hex_color((string)$data['pathpointcolor']);
        }
        if (isset($data['nextbuttoncolor'])) {
            $fallback = \local_selfstudy\helper::normalise_hex_color((string)($data['pathpointcolor'] ?? '#6f1ab1'));
            $data['nextbuttoncolor'] = \local_selfstudy\helper::normalise_hex_color((string)$data['nextbuttoncolor'],
                $fallback);
        }

        return $data;
    }

    /**
     * Section level format options.
     *
     * @param bool $foreditform
     * @return array
     */
    public function section_format_options($foreditform = false): array {
        $options = [
            'learninggoal' => [
                'default' => '',
                'type' => PARAM_TEXT,
            ],
            'pathkind' => [
                'default' => 'required',
                'type' => PARAM_ALPHA,
            ],
        ];

        if ($foreditform) {
            $options['learninggoal']['label'] = get_string('learninggoal', 'format_selfstudy');
            $options['learninggoal']['help'] = 'learninggoal';
            $options['learninggoal']['help_component'] = 'format_selfstudy';
            $options['learninggoal']['element_type'] = 'textarea';
            $options['learninggoal']['element_attributes'] = [['rows' => 3, 'cols' => 60]];

            $options['pathkind']['label'] = get_string('pathkind', 'format_selfstudy');
            $options['pathkind']['help'] = 'pathkind';
            $options['pathkind']['help_component'] = 'format_selfstudy';
            $options['pathkind']['element_type'] = 'select';
            $options['pathkind']['element_attributes'] = [[
                'required' => get_string('required', 'format_selfstudy'),
                'optional' => get_string('optional', 'format_selfstudy'),
            ]];
        }

        return $options;
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
    if (!has_capability('moodle/course:view', $context)) {
        return false;
    }

    $filename = array_pop($args);
    if (!$filename || $filename === '.') {
        return false;
    }

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'format_selfstudy', $filearea, 0, '/', $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

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
