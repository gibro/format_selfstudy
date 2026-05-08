<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/course/format/lib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Course format class for self-directed learning courses.
 */
class format_selfstudy extends core_courseformat\base {

    /**
     * Adds activity navigation to course pages.
     *
     * @param moodle_page $page
     */
    public function page_set_course(moodle_page $page): void {
        global $OUTPUT, $USER;

        $options = $this->get_format_options();
        $shownavigation = !empty($options['enableactivitynavigation']);
        $currentcmid = optional_param('id', 0, PARAM_INT);
        $currentmodname = null;
        $currentactivityid = 0;

        $mapurl = new moodle_url('/course/view.php', ['id' => $this->courseid]);
        $mapbackgroundurl = null;
        $modinfo = null;
        try {
            $modinfo = get_fast_modinfo($this->courseid);
            if ($currentcmid) {
                $currentcm = $modinfo->get_cm($currentcmid);
                if ((int)$currentcm->course === (int)$this->courseid) {
                    $currentmodname = $currentcm->modname;
                }
            }
        } catch (Throwable $exception) {
            $modinfo = null;
        }

        if ($modinfo) {
            $currentactivityid = $this->get_current_learning_activity_id($modinfo);
        }

        $avatarconfig = $this->get_avatar_marker_config($page, $OUTPUT, $USER);

        if ($currentmodname === 'learningmap') {
            $page->requires->js_call_amd('format_selfstudy/navigation', 'init', [[
                'learningmapMode' => true,
                'activityUrls' => $this->get_ordered_learning_activity_urls(),
                'currentActivityId' => $currentactivityid,
                'courseId' => (int)$this->courseid,
                'avatarImageUrl' => $avatarconfig['imageurl'],
                'avatarLabel' => $avatarconfig['label'],
            ]]);
            return;
        }

        if (!empty($options['mainlearningmap'])) {
            try {
                $cm = $modinfo ? $modinfo->get_cm((int)$options['mainlearningmap']) :
                    get_fast_modinfo($this->courseid)->get_cm((int)$options['mainlearningmap']);
                if ($cm && $cm->uservisible && $cm->modname === 'learningmap') {
                    $mapurl = new moodle_url('/mod/learningmap/view.php', ['id' => $cm->id]);
                }
            } catch (Throwable $exception) {
                $mapurl = new moodle_url('/course/view.php', ['id' => $this->courseid]);
                $mapbackgroundurl = null;
            }
        }

        if (!$shownavigation && !$mapbackgroundurl) {
            return;
        }

        $activitynav = $this->get_activity_navigation_urls();
        $page->requires->js_call_amd('format_selfstudy/navigation', 'init', [[
            'showNavigation' => $shownavigation,
            'previousLabel' => $options['previousbuttonlabel'] ?? get_string('previousbuttonlabel_default', 'format_selfstudy'),
            'mapLabel' => $options['mapbuttonlabel'] ?? get_string('mapbuttonlabel_default', 'format_selfstudy'),
            'nextLabel' => $options['nextbuttonlabel'] ?? get_string('nextbuttonlabel_default', 'format_selfstudy'),
            'mapUrl' => $mapurl->out(false),
            'mapBackgroundUrl' => $mapbackgroundurl ? $mapbackgroundurl->out(false) : null,
            'previousUrl' => $activitynav['previousurl'],
            'nextUrl' => $activitynav['nexturl'],
            'currentActivityId' => $currentactivityid,
            'courseId' => (int)$this->courseid,
            'avatarImageUrl' => $avatarconfig['imageurl'],
            'avatarLabel' => $avatarconfig['label'],
        ]]);
    }

    /**
     * Returns the activity that should be marked as the learner's current map position.
     *
     * @param course_modinfo $modinfo
     * @return int
     */
    protected function get_current_learning_activity_id(course_modinfo $modinfo): int {
        global $USER;

        $firstactivityid = 0;
        $firstopenactivityid = 0;
        $latestcompletedid = 0;
        $latestcompletedtime = 0;

        try {
            $completion = new completion_info($this->get_course());
        } catch (Throwable $exception) {
            $completion = null;
        }

        foreach ($modinfo->get_section_info_all() as $section) {
            if (!$section->uservisible || empty($modinfo->sections[$section->section])) {
                continue;
            }

            foreach ($modinfo->sections[$section->section] as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if (!$cm->uservisible || empty($cm->url) || !$this->is_learning_activity($cm)) {
                    continue;
                }

                if (!$firstactivityid) {
                    $firstactivityid = (int)$cm->id;
                }

                if (!$completion || !$completion->is_enabled($cm)) {
                    if (!$firstopenactivityid) {
                        $firstopenactivityid = (int)$cm->id;
                    }
                    continue;
                }

                $data = $completion->get_data($cm, false, $USER->id);
                if ($this->is_completion_done((int)$data->completionstate)) {
                    $completedtime = (int)($data->timemodified ?? 0);
                    if (!$latestcompletedid || $completedtime >= $latestcompletedtime) {
                        $latestcompletedid = (int)$cm->id;
                        $latestcompletedtime = $completedtime;
                    }
                    continue;
                }

                if (!$firstopenactivityid) {
                    $firstopenactivityid = (int)$cm->id;
                }
            }
        }

        return $latestcompletedid ?: ($firstopenactivityid ?: $firstactivityid);
    }

    /**
     * Builds the avatar marker configuration for JavaScript.
     *
     * @param moodle_page $page
     * @param mixed $output
     * @param stdClass $user
     * @return array
     */
    protected function get_avatar_marker_config(moodle_page $page, $output, stdClass $user): array {
        $imageurl = '';

        try {
            $picture = new user_picture($user);
            $picture->size = 80;
            $picture->link = false;
            $picture->alttext = false;
            $imageurl = $picture->get_url($page, $output)->out(false);
        } catch (Throwable $exception) {
            $imageurl = '';
        }

        return [
            'imageurl' => $imageurl,
            'label' => get_string('currentposition', 'format_selfstudy') . ': ' . fullname($user),
        ];
    }

    /**
     * Calculates previous and next activity URLs for the current activity page.
     *
     * @return array
     */
    protected function get_activity_navigation_urls(): array {
        $urls = [
            'previousurl' => null,
            'nexturl' => null,
        ];

        $currentcmid = optional_param('id', 0, PARAM_INT);
        if (!$currentcmid) {
            return $urls;
        }

        try {
            $modinfo = get_fast_modinfo($this->courseid);
            $currentcm = $modinfo->get_cm($currentcmid);
        } catch (Throwable $exception) {
            return $urls;
        }

        if ((int)$currentcm->course !== (int)$this->courseid || !$currentcm->uservisible) {
            return $urls;
        }

        $orderedcms = [];
        $currentposition = null;
        foreach ($modinfo->get_section_info_all() as $section) {
            if (!$section->uservisible || empty($modinfo->sections[$section->section])) {
                continue;
            }

            foreach ($modinfo->sections[$section->section] as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if (!$cm->uservisible || empty($cm->url)) {
                    continue;
                }

                $position = count($orderedcms);
                if ((int)$cm->id === (int)$currentcmid) {
                    $currentposition = $position;
                }

                if (!$this->is_learning_activity($cm) && (int)$cm->id !== (int)$currentcmid) {
                    continue;
                }

                $orderedcms[] = $cm;
            }
        }

        if ($currentposition !== null) {
            for ($index = $currentposition - 1; $index >= 0; $index--) {
                if ($this->is_learning_activity($orderedcms[$index])) {
                    $urls['previousurl'] = $orderedcms[$index]->url->out(false);
                    break;
                }
            }

            for ($index = $currentposition + 1; $index < count($orderedcms); $index++) {
                if ($this->is_learning_activity($orderedcms[$index])) {
                    $urls['nexturl'] = $orderedcms[$index]->url->out(false);
                    break;
                }
            }
        }

        return $urls;
    }

    /**
     * Returns ordered learning activity URLs for the fullscreen map popup.
     *
     * @return array
     */
    protected function get_ordered_learning_activity_urls(): array {
        global $USER;

        $activities = [];

        try {
            $modinfo = get_fast_modinfo($this->courseid);
            $completion = new completion_info($this->get_course());
        } catch (Throwable $exception) {
            return $activities;
        }

        foreach ($modinfo->get_section_info_all() as $section) {
            if (!$section->uservisible || empty($modinfo->sections[$section->section])) {
                continue;
            }

            foreach ($modinfo->sections[$section->section] as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if (!$cm->uservisible || empty($cm->url) || !$this->is_learning_activity($cm)) {
                    continue;
                }

                $statuskey = 'available';
                $statuslabel = get_string('statusavailable', 'format_selfstudy');
                $completiontime = 0;
                if ($completion->is_enabled($cm)) {
                    $data = $completion->get_data($cm, false, $USER->id);
                    if ($this->is_completion_done((int)$data->completionstate)) {
                        $statuskey = 'complete';
                        $statuslabel = get_string('statuscomplete', 'format_selfstudy');
                        $completiontime = (int)($data->timemodified ?? 0);
                    } else {
                        $statuskey = 'notstarted';
                        $statuslabel = get_string('statusnotstarted', 'format_selfstudy');
                    }
                }

                $activities[] = [
                    'id' => (int)$cm->id,
                    'name' => format_string($cm->name, true),
                    'url' => $cm->url->out(false),
                    'completionStatus' => $statuskey,
                    'completionLabel' => $statuslabel,
                    'completionTime' => $completiontime,
                ];
            }
        }

        return $activities;
    }

    /**
     * Checks whether a course module should be used in activity navigation.
     *
     * @param cm_info $cm
     * @return bool
     */
    protected function is_learning_activity(cm_info $cm): bool {
        $excludedmods = [
            'learningmap',
            'qbank',
        ];

        return !in_array($cm->modname, $excludedmods, true);
    }

    /**
     * Checks whether a completion state is done.
     *
     * @param int $completionstate
     * @return bool
     */
    protected function is_completion_done(int $completionstate): bool {
        return $completionstate === COMPLETION_COMPLETE || $completionstate === COMPLETION_COMPLETE_PASS;
    }

    /**
     * Course level format options.
     *
     * @param bool $foreditform
     * @return array
     */
    public function course_format_options($foreditform = false): array {
        $options = [
            'mainlearningmap' => [
                'default' => 0,
                'type' => PARAM_INT,
            ],
            'enablelistview' => [
                'default' => 1,
                'type' => PARAM_BOOL,
            ],
            'enableactivitynavigation' => [
                'default' => 1,
                'type' => PARAM_BOOL,
            ],
            'showactivitystatus' => [
                'default' => 1,
                'type' => PARAM_BOOL,
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
        ];

        if ($foreditform) {
            $options['mainlearningmap']['label'] = get_string('mainlearningmap', 'format_selfstudy');
            $options['mainlearningmap']['help'] = 'mainlearningmap';
            $options['mainlearningmap']['help_component'] = 'format_selfstudy';
            $options['mainlearningmap']['element_type'] = 'select';
            $options['mainlearningmap']['element_attributes'] = [$this->get_learningmap_options()];

            $options['enablelistview']['label'] = get_string('enablelistview', 'format_selfstudy');
            $options['enablelistview']['help'] = 'enablelistview';
            $options['enablelistview']['help_component'] = 'format_selfstudy';
            $options['enablelistview']['element_type'] = 'advcheckbox';

            $options['enableactivitynavigation']['label'] = get_string('enableactivitynavigation', 'format_selfstudy');
            $options['enableactivitynavigation']['help'] = 'enableactivitynavigation';
            $options['enableactivitynavigation']['help_component'] = 'format_selfstudy';
            $options['enableactivitynavigation']['element_type'] = 'advcheckbox';

            $options['showactivitystatus']['label'] = get_string('showactivitystatus', 'format_selfstudy');
            $options['showactivitystatus']['help'] = 'showactivitystatus';
            $options['showactivitystatus']['help_component'] = 'format_selfstudy';
            $options['showactivitystatus']['element_type'] = 'advcheckbox';

            $options['previousbuttonlabel']['label'] = get_string('previousbuttonlabel', 'format_selfstudy');
            $options['previousbuttonlabel']['element_type'] = 'text';

            $options['mapbuttonlabel']['label'] = get_string('mapbuttonlabel', 'format_selfstudy');
            $options['mapbuttonlabel']['element_type'] = 'text';

            $options['nextbuttonlabel']['label'] = get_string('nextbuttonlabel', 'format_selfstudy');
            $options['nextbuttonlabel']['element_type'] = 'text';
        }

        return $options;
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
            'sectionmap' => [
                'default' => 0,
                'type' => PARAM_INT,
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

            $options['sectionmap']['label'] = get_string('sectionmap', 'format_selfstudy');
            $options['sectionmap']['help'] = 'sectionmap';
            $options['sectionmap']['help_component'] = 'format_selfstudy';
            $options['sectionmap']['element_type'] = 'select';
            $options['sectionmap']['element_attributes'] = [$this->get_learningmap_options()];
        }

        return $options;
    }

    /**
     * Returns Learningmap course module options for select fields.
     *
     * @return array
     */
    protected function get_learningmap_options(): array {
        $maps = [0 => get_string('nolearningmap', 'format_selfstudy')];

        try {
            $modinfo = get_fast_modinfo($this->courseid);
        } catch (Throwable $exception) {
            return $maps;
        }

        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->modname === 'learningmap' && $cm->visible) {
                $maps[$cm->id] = format_string($cm->name, true);
            }
        }

        return $maps;
    }
}

/**
 * Adds selfstudy fields to activity settings forms.
 *
 * @param moodleform_mod $formwrapper
 * @param MoodleQuickForm $mform
 */
function format_selfstudy_coursemodule_standard_elements($formwrapper, $mform): void {
    $course = $formwrapper->get_course();
    if (course_get_format($course)->get_format() !== 'selfstudy') {
        return;
    }

    $cm = $formwrapper->get_coursemodule();
    $learninggoal = $cm ? format_selfstudy_get_cm_learninggoal((int)$cm->id) : '';

    $mform->addElement('textarea', 'format_selfstudy_learninggoal',
        get_string('activitylearninggoal', 'format_selfstudy'), ['rows' => 3, 'cols' => 60]);
    $mform->addHelpButton('format_selfstudy_learninggoal', 'activitylearninggoal', 'format_selfstudy');
    $mform->setType('format_selfstudy_learninggoal', PARAM_TEXT);
    $mform->setDefault('format_selfstudy_learninggoal', $learninggoal);
}

/**
 * Saves selfstudy fields from activity settings forms.
 *
 * @param stdClass $moduleinfo
 * @param stdClass $course
 * @return stdClass
 */
function format_selfstudy_coursemodule_edit_post_actions(stdClass $moduleinfo, stdClass $course): stdClass {
    if (course_get_format($course)->get_format() !== 'selfstudy' || empty($moduleinfo->coursemodule)) {
        return $moduleinfo;
    }

    if (!property_exists($moduleinfo, 'format_selfstudy_learninggoal')) {
        return $moduleinfo;
    }

    format_selfstudy_save_cm_learninggoal(
        (int)$moduleinfo->coursemodule,
        clean_param($moduleinfo->format_selfstudy_learninggoal, PARAM_TEXT)
    );

    return $moduleinfo;
}

/**
 * Returns the stored learning goal for a course module.
 *
 * @param int $cmid
 * @return string
 */
function format_selfstudy_get_cm_learninggoal(int $cmid): string {
    global $DB;

    if (!$cmid || !format_selfstudy_cmgoals_table_exists()) {
        return '';
    }

    $cache = &format_selfstudy_cm_learninggoal_cache();
    if (array_key_exists($cmid, $cache)) {
        return $cache[$cmid];
    }

    $record = $DB->get_record('format_selfstudy_cmgoals', ['cmid' => $cmid], 'learninggoal', IGNORE_MISSING);
    $cache[$cmid] = $record ? (string)$record->learninggoal : '';

    return $cache[$cmid];
}

/**
 * Saves or removes the learning goal for a course module.
 *
 * @param int $cmid
 * @param string $learninggoal
 */
function format_selfstudy_save_cm_learninggoal(int $cmid, string $learninggoal): void {
    global $DB;

    if (!$cmid || !format_selfstudy_cmgoals_table_exists()) {
        return;
    }

    $learninggoal = trim($learninggoal);
    $existing = $DB->get_record('format_selfstudy_cmgoals', ['cmid' => $cmid], '*', IGNORE_MISSING);
    if ($learninggoal === '') {
        if ($existing) {
            $DB->delete_records('format_selfstudy_cmgoals', ['cmid' => $cmid]);
        }
        $cache = &format_selfstudy_cm_learninggoal_cache();
        $cache[$cmid] = '';
        return;
    }

    $now = time();
    if ($existing) {
        $existing->learninggoal = $learninggoal;
        $existing->timemodified = $now;
        $DB->update_record('format_selfstudy_cmgoals', $existing);
    } else {
        $record = (object)[
            'cmid' => $cmid,
            'learninggoal' => $learninggoal,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $DB->insert_record('format_selfstudy_cmgoals', $record);
    }

    $cache = &format_selfstudy_cm_learninggoal_cache();
    $cache[$cmid] = $learninggoal;
}

/**
 * Returns an in-request cache for activity learning goals.
 *
 * @return array
 */
function &format_selfstudy_cm_learninggoal_cache(): array {
    static $cache = [];
    return $cache;
}

/**
 * Checks whether the activity learning goal table exists.
 *
 * @return bool
 */
function format_selfstudy_cmgoals_table_exists(): bool {
    global $DB;

    static $exists = null;
    if ($exists === null) {
        $exists = $DB->get_manager()->table_exists('format_selfstudy_cmgoals');
    }

    return $exists;
}
