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
     * Returns true because selfstudy courses are section based.
     *
     * @return bool
     */
    public function uses_sections() {
        return true;
    }

    /**
     * Returns true to let Moodle render the core course index drawer.
     *
     * @return bool
     */
    public function uses_course_index() {
        return true;
    }

    /**
     * Adds activity navigation to course pages.
     *
     * @param moodle_page $page
     */
    public function page_set_course(moodle_page $page): void {
        global $OUTPUT, $USER;

        $this->add_course_more_navigation($page);
        if (!$this->is_learning_navigation_page($page)) {
            $page->add_body_class('format-selfstudy-hide-courseindex');
            return;
        }

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
        $pathoutline = $this->get_active_learning_path_outline();
        $showpathui = $this->should_show_learning_path_ui();
        $pathpointcolor = format_selfstudy_normalise_hex_color($options['pathpointcolor'] ?? '#6f1ab1');

        $avatarconfig = !empty($options['enableavatar']) ?
            $this->get_avatar_marker_config($page, $OUTPUT, $USER) : ['imageurl' => '', 'label' => ''];

        if ($currentmodname === 'learningmap') {
            $page->requires->js_call_amd('format_selfstudy/navigation', 'init', [[
                'learningmapMode' => true,
                'activityUrls' => $this->get_ordered_learning_activity_urls(),
                'currentActivityId' => $currentactivityid,
                'currentCmId' => $currentcmid,
                'courseId' => (int)$this->courseid,
                'avatarEnabled' => !empty($options['enableavatar']),
                'avatarImageUrl' => $avatarconfig['imageurl'],
                'avatarLabel' => $avatarconfig['label'],
                'currentStatusLabel' => get_string('learningpathstatuscurrent', 'format_selfstudy'),
                'competenciesLabel' => get_string('activitycompetencies', 'format_selfstudy'),
                'pathTitle' => get_string('learningpathcurrent', 'format_selfstudy'),
                'pathOutline' => $showpathui ? $pathoutline : [],
                'showPathOutline' => $showpathui,
                'pathPointColor' => $pathpointcolor,
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

        $experiencehints = $this->get_experience_navigation_hints($currentcmid);
        if (!empty($experiencehints->mapurl)) {
            $mapurl = new moodle_url($experiencehints->mapurl);
        }
        if (!empty($experiencehints->mapbackgroundurl)) {
            $mapbackgroundurl = new moodle_url($experiencehints->mapbackgroundurl);
        }

        if (!$shownavigation && !$mapbackgroundurl && !$pathoutline) {
            return;
        }

        $activitynav = $this->get_active_path_navigation_urls($pathoutline) ?: $this->get_activity_navigation_urls();
        if (!empty($experiencehints->previousurl)) {
            $activitynav['previousurl'] = (string)$experiencehints->previousurl;
        }
        if (!empty($experiencehints->nexturl)) {
            $activitynav['nexturl'] = (string)$experiencehints->nexturl;
        }
        $page->requires->js_call_amd('format_selfstudy/navigation', 'init', [[
            'showNavigation' => $shownavigation,
            'showPreviousButton' => !empty($options['shownavprevious']),
            'showMapButton' => !empty($options['shownavmap']),
            'showNextButton' => !empty($options['shownavnext']),
            'previousLabel' => $options['previousbuttonlabel'] ?? get_string('previousbuttonlabel_default', 'format_selfstudy'),
            'mapLabel' => $options['mapbuttonlabel'] ?? get_string('mapbuttonlabel_default', 'format_selfstudy'),
            'nextLabel' => $options['nextbuttonlabel'] ?? get_string('nextbuttonlabel_default', 'format_selfstudy'),
            'customButtonLabel' => $options['customnavbuttonlabel'] ?? '',
            'customButtonUrl' => $options['customnavbuttonurl'] ?? '',
            'mapUrl' => $mapurl->out(false),
            'mapBackgroundUrl' => $mapbackgroundurl ? $mapbackgroundurl->out(false) : null,
            'previousUrl' => $activitynav['previousurl'],
            'nextUrl' => $activitynav['nexturl'],
            'pathNextUrl' => $activitynav['nexturl'],
            'continueLabel' => get_string('learningpathcontinue', 'format_selfstudy'),
            'completionUrl' => (new moodle_url('/course/view.php', ['id' => $this->courseid]))->out(false),
            'completionLabel' => get_string('learningpathcompleteoverview', 'format_selfstudy'),
            'completionTitle' => get_string('learningpathcompleteoverviewtitle', 'format_selfstudy'),
            'currentStatusLabel' => get_string('learningpathstatuscurrent', 'format_selfstudy'),
            'competenciesLabel' => get_string('activitycompetencies', 'format_selfstudy'),
            'currentActivityId' => $currentactivityid,
            'currentCmId' => $currentcmid,
            'courseId' => (int)$this->courseid,
            'avatarEnabled' => !empty($options['enableavatar']),
            'avatarImageUrl' => $avatarconfig['imageurl'],
            'avatarLabel' => $avatarconfig['label'],
            'pathTitle' => get_string('learningpathcurrent', 'format_selfstudy'),
            'pathOutline' => $showpathui ? $pathoutline : [],
            'showPathOutline' => $showpathui,
            'pathPointColor' => $pathpointcolor,
        ]]);
    }

    /**
     * Returns whether the visible learning path UI is needed for this course.
     *
     * @return bool
     */
    protected function should_show_learning_path_ui(): bool {
        $options = $this->get_format_options();
        if (!empty($options['allowpersonalpaths'])) {
            return true;
        }

        try {
            $repository = new \format_selfstudy\local\path_repository();
            return count($repository->get_paths((int)$this->courseid, true)) > 1;
        } catch (Throwable $exception) {
            return false;
        }
    }

    /**
     * Returns whether selfstudy learner navigation should be injected on this page.
     *
     * @param moodle_page $page
     * @return bool
     */
    protected function is_learning_navigation_page(moodle_page $page): bool {
        $path = $page->url ? $page->url->get_path(false) : '';

        return $path === '/course/view.php' || strpos($path, '/mod/') === 0;
    }

    /**
     * Returns the active learning path outline for the current user.
     *
     * @return array
     */
    protected function get_active_learning_path_outline(): array {
        global $USER;

        try {
            $baseview = \format_selfstudy\local\base_view::create($this->get_course(), (int)$USER->id);
            if (empty($baseview->path)) {
                return [];
            }

            $outline = $baseview->outline;
        } catch (Throwable $exception) {
            return [];
        }

        $options = $this->get_format_options();
        if (empty($options['showlockedactivities'])) {
            $outline = array_values(array_filter($outline, static function(stdClass $entry): bool {
                return $entry->status !== 'locked';
            }));
        }

        $items = [];
        foreach ($outline as $entry) {
            $items[] = [
                'id' => (int)$entry->id,
                'type' => (string)$entry->type,
                'level' => (int)$entry->level,
                'title' => (string)$entry->title,
                'status' => (string)$entry->status,
                'statusLabel' => $this->get_path_status_label((string)$entry->status),
                'ariaLabel' => $this->get_path_entry_aria_label($entry),
                'url' => $entry->url,
                'actionUrl' => $entry->actionurl,
                'actionLabel' => $entry->actionlabel,
                'cmid' => (int)($entry->cmid ?? 0),
                'iconUrl' => (string)($entry->iconurl ?? ''),
                'iconAlt' => (string)($entry->iconalt ?? ''),
                'milestoneComplete' => !empty($entry->milestonecomplete),
                'milestoneChild' => !empty($entry->milestonechild),
                'milestoneGroupStart' => !empty($entry->milestonegroupstart),
                'milestoneGroupEnd' => !empty($entry->milestonegroupend),
                'alternativeGroup' => (string)($entry->alternativegroup ?? ''),
                'alternativeChoice' => !empty($entry->alternativechoice),
                'alternativeChoiceIndex' => (int)($entry->alternativechoiceindex ?? 0),
                'timeModified' => (int)($entry->timemodified ?? 0),
                'availableInfo' => (string)($entry->availableinfo ?? ''),
            ];
        }

        return $items;
    }

    /**
     * Returns a localized path status label.
     *
     * @param string $status
     * @return string
     */
    protected function get_path_status_label(string $status): string {
        return get_string('learningpathstatus' . $status, 'format_selfstudy');
    }

    /**
     * Returns a screen reader label for a path outline entry.
     *
     * @param stdClass $entry
     * @return string
     */
    protected function get_path_entry_aria_label(stdClass $entry): string {
        $statuslabel = $this->get_path_status_label((string)$entry->status);
        $parts = [
            (string)$entry->title,
            get_string('learningpathaccessiblestatus', 'format_selfstudy', $statuslabel),
        ];
        if (!empty($entry->availableinfo)) {
            $parts[] = trim(strip_tags((string)$entry->availableinfo));
        }

        return implode('. ', array_filter($parts));
    }

    /**
     * Returns previous/next URLs based on the active learning path outline.
     *
     * @param array $pathoutline
     * @return array|null
     */
    protected function get_active_path_navigation_urls(array $pathoutline): ?array {
        $currentcmid = optional_param('id', 0, PARAM_INT);
        if (!$currentcmid || !$pathoutline) {
            return null;
        }

        $stations = array_values(array_filter($pathoutline, static function(array $entry) use ($currentcmid): bool {
            return !empty($entry['cmid']) && !empty($entry['url']);
        }));
        $currentindex = null;
        foreach ($stations as $index => $entry) {
            if ((int)$entry['cmid'] === (int)$currentcmid) {
                $currentindex = $index;
                break;
            }
        }
        if ($currentindex === null) {
            return null;
        }

        $previousurl = $currentindex > 0 ? $stations[$currentindex - 1]['url'] : null;
        $nexturl = null;
        for ($index = $currentindex + 1; $index < count($stations); $index++) {
            if (!in_array($stations[$index]['status'], ['complete', 'locked', 'missing'], true)) {
                $nexturl = $stations[$index]['url'];
                break;
            }
        }
        if (!$nexturl && $currentindex < count($stations) - 1) {
            $nexturl = $stations[$currentindex + 1]['url'];
        }

        return [
            'previousurl' => $previousurl,
            'nexturl' => $nexturl,
        ];
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
     * Returns optional navigation hints from active experiences.
     *
     * @param int $currentcmid
     * @return stdClass|null
     */
    protected function get_experience_navigation_hints(int $currentcmid): ?stdClass {
        global $USER;

        if (!$currentcmid) {
            return null;
        }

        try {
            $course = $this->get_course();
            $modinfo = get_fast_modinfo($course);
            $cm = $modinfo->get_cm($currentcmid);
            if ((int)$cm->course !== (int)$course->id || !$cm->uservisible) {
                return null;
            }

            $baseview = \format_selfstudy\local\base_view::create($course, (int)$USER->id);
            $registry = new \format_selfstudy\local\experience_registry();
            return $registry->get_activity_navigation($course, $baseview, $cm);
        } catch (Throwable $exception) {
            debugging('Selfstudy experience activity navigation failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
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
     * Adds selfstudy administration links to Moodle's course "More" menu.
     *
     * @param moodle_page $page
     */
    protected function add_course_more_navigation(moodle_page $page): void {
        try {
            $course = $this->get_course();
            $coursecontext = context_course::instance($course->id);
            if (!has_capability('moodle/course:update', $coursecontext)) {
                return;
            }

            $settingsnav = $page->settingsnav;
            $courseadminnode = $settingsnav->find('courseadmin', navigation_node::TYPE_COURSE);
            if (!$courseadminnode) {
                return;
            }

            if ($courseadminnode->find('format_selfstudy', navigation_node::TYPE_CONTAINER)) {
                return;
            }

            $editorurl = new moodle_url('/course/format/selfstudy/path_editor.php', ['id' => $course->id]);
            $selfstudynode = $courseadminnode->add(
                get_string('selfstudynavigation', 'format_selfstudy'),
                $editorurl,
                navigation_node::TYPE_CONTAINER,
                null,
                'format_selfstudy',
                new pix_icon('i/settings', '')
            );
            $selfstudynode->set_force_into_more_menu(true);

            $selfstudynode->add(
                get_string('learningpatheditor', 'format_selfstudy'),
                $editorurl,
                navigation_node::TYPE_SETTING,
                null,
                'format_selfstudy_path_editor',
                new pix_icon('t/edit', '')
            );

            $importurl = new moodle_url('/course/format/selfstudy/path_import.php', ['id' => $course->id]);
            $selfstudynode->add(
                get_string('learningpathimport', 'format_selfstudy'),
                $importurl,
                navigation_node::TYPE_SETTING,
                null,
                'format_selfstudy_path_import',
                new pix_icon('i/import', '')
            );

            $experienceurl = new moodle_url('/course/format/selfstudy/experience_settings.php', ['id' => $course->id]);
            $selfstudynode->add(
                get_string('experiencesettings', 'format_selfstudy'),
                $experienceurl,
                navigation_node::TYPE_SETTING,
                null,
                'format_selfstudy_experience_settings',
                new pix_icon('i/settings', '')
            );

            $settingsurl = new moodle_url('/course/edit.php', ['id' => $course->id]);
            $settingsurl->set_anchor('id_format_selfstudy');
            $selfstudynode->add(
                get_string('formatoptions', 'format_selfstudy'),
                $settingsurl,
                navigation_node::TYPE_SETTING,
                null,
                'format_selfstudy_settings',
                new pix_icon('i/settings', '')
            );
        } catch (Throwable $exception) {
            return;
        }
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
            'defaultview' => [
                'default' => 'dashboard',
                'type' => PARAM_ALPHA,
            ],
            'mainlearningmap' => [
                'default' => 0,
                'type' => PARAM_INT,
            ],
            'enabledashboard' => [
                'default' => 1,
                'type' => PARAM_BOOL,
            ],
            'enablelistview' => [
                'default' => 1,
                'type' => PARAM_BOOL,
            ],
            'enablesectionmaps' => [
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

            $options['defaultview']['label'] = get_string('defaultview', 'format_selfstudy');
            $options['defaultview']['help'] = 'defaultview';
            $options['defaultview']['help_component'] = 'format_selfstudy';
            $options['defaultview']['element_type'] = 'select';
            $options['defaultview']['element_attributes'] = [[
                'dashboard' => get_string('defaultviewdashboard', 'format_selfstudy'),
                'map' => get_string('defaultviewmap', 'format_selfstudy'),
                'list' => get_string('defaultviewlist', 'format_selfstudy'),
            ]];

            $options['mainlearningmap']['label'] = get_string('mainlearningmap', 'format_selfstudy');
            $options['mainlearningmap']['help'] = 'mainlearningmap';
            $options['mainlearningmap']['help_component'] = 'format_selfstudy';
            $options['mainlearningmap']['element_type'] = 'select';
            $options['mainlearningmap']['element_attributes'] = [$this->get_learningmap_options()];

            $options['enabledashboard']['label'] = get_string('enabledashboard', 'format_selfstudy');
            $options['enabledashboard']['help'] = 'enabledashboard';
            $options['enabledashboard']['help_component'] = 'format_selfstudy';
            $options['enabledashboard']['element_type'] = 'advcheckbox';

            $options['enablelistview']['label'] = get_string('enablelistview', 'format_selfstudy');
            $options['enablelistview']['help'] = 'enablelistview';
            $options['enablelistview']['help_component'] = 'format_selfstudy';
            $options['enablelistview']['element_type'] = 'advcheckbox';

            $options['enablesectionmaps']['label'] = get_string('enablesectionmaps', 'format_selfstudy');
            $options['enablesectionmaps']['help'] = 'enablesectionmaps';
            $options['enablesectionmaps']['help_component'] = 'format_selfstudy';
            $options['enablesectionmaps']['element_type'] = 'advcheckbox';

            $options['enableavatar']['label'] = get_string('enableavatar', 'format_selfstudy');
            $options['enableavatar']['help'] = 'enableavatar';
            $options['enableavatar']['help_component'] = 'format_selfstudy';
            $options['enableavatar']['element_type'] = 'advcheckbox';

            $options['enableactivitynavigation']['label'] = get_string('enableactivitynavigation', 'format_selfstudy');
            $options['enableactivitynavigation']['help'] = 'enableactivitynavigation';
            $options['enableactivitynavigation']['help_component'] = 'format_selfstudy';
            $options['enableactivitynavigation']['element_type'] = 'advcheckbox';

            $options['shownavprevious']['label'] = get_string('shownavprevious', 'format_selfstudy');
            $options['shownavprevious']['element_type'] = 'advcheckbox';

            $options['shownavmap']['label'] = get_string('shownavmap', 'format_selfstudy');
            $options['shownavmap']['element_type'] = 'advcheckbox';

            $options['shownavnext']['label'] = get_string('shownavnext', 'format_selfstudy');
            $options['shownavnext']['element_type'] = 'advcheckbox';

            $options['showactivitystatus']['label'] = get_string('showactivitystatus', 'format_selfstudy');
            $options['showactivitystatus']['help'] = 'showactivitystatus';
            $options['showactivitystatus']['help_component'] = 'format_selfstudy';
            $options['showactivitystatus']['element_type'] = 'advcheckbox';

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
                'class' => 'format-selfstudy-colorinput',
            ]];

            $options['previousbuttonlabel']['label'] = get_string('previousbuttonlabel', 'format_selfstudy');
            $options['previousbuttonlabel']['element_type'] = 'text';

            $options['mapbuttonlabel']['label'] = get_string('mapbuttonlabel', 'format_selfstudy');
            $options['mapbuttonlabel']['element_type'] = 'text';

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

        if (!$forsection && $mform->elementExists('pathpointcolor')) {
            $element = $mform->getElement('pathpointcolor');
            if (method_exists($element, 'setType')) {
                $element->setType('color');
            }
            $element->updateAttributes([
                'title' => get_string('pathpointcolor', 'format_selfstudy'),
            ]);
        }

        return $elements;
    }

    /**
     * Validates format options for the course.
     *
     * @param array $data
     * @return array
     */
    public function validate_course_format_options(array $data): array {
        $data = parent::validate_course_format_options($data);
        if (isset($data['pathpointcolor'])) {
            $data['pathpointcolor'] = format_selfstudy_normalise_hex_color((string)$data['pathpointcolor']);
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

            $courseoptions = $this->get_format_options();
            if (!empty($courseoptions['enablesectionmaps'])) {
                $options['sectionmap']['label'] = get_string('sectionmap', 'format_selfstudy');
                $options['sectionmap']['help'] = 'sectionmap';
                $options['sectionmap']['help_component'] = 'format_selfstudy';
                $options['sectionmap']['element_type'] = 'select';
                $options['sectionmap']['element_attributes'] = [$this->get_learningmap_options()];
            }
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
    $metadata = $cm ? format_selfstudy_get_cm_metadata((int)$cm->id) : (object)[
        'learninggoal' => '',
        'durationminutes' => 0,
    ];

    $mform->addElement('textarea', 'format_selfstudy_learninggoal',
        get_string('activitylearninggoal', 'format_selfstudy'), ['rows' => 3, 'cols' => 60]);
    $mform->addHelpButton('format_selfstudy_learninggoal', 'activitylearninggoal', 'format_selfstudy');
    $mform->setType('format_selfstudy_learninggoal', PARAM_TEXT);
    $mform->setDefault('format_selfstudy_learninggoal', $metadata->learninggoal);

    $mform->addElement('text', 'format_selfstudy_durationminutes',
        get_string('activitydurationminutes', 'format_selfstudy'), ['size' => 8]);
    $mform->addHelpButton('format_selfstudy_durationminutes', 'activitydurationminutes', 'format_selfstudy');
    $mform->setType('format_selfstudy_durationminutes', PARAM_INT);
    $mform->setDefault('format_selfstudy_durationminutes', $metadata->durationminutes);
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

    if (!property_exists($moduleinfo, 'format_selfstudy_learninggoal') &&
            !property_exists($moduleinfo, 'format_selfstudy_durationminutes')) {
        return $moduleinfo;
    }

    format_selfstudy_save_cm_metadata((int)$moduleinfo->coursemodule, (object)[
        'learninggoal' => clean_param($moduleinfo->format_selfstudy_learninggoal ?? '', PARAM_TEXT),
        'durationminutes' => clean_param($moduleinfo->format_selfstudy_durationminutes ?? 0, PARAM_INT),
        'competencies' => '',
    ]);

    return $moduleinfo;
}

/**
 * Returns the stored learning goal for a course module.
 *
 * @param int $cmid
 * @return string
 */
function format_selfstudy_get_cm_learninggoal(int $cmid): string {
    return format_selfstudy_get_cm_metadata($cmid)->learninggoal;
}

/**
 * Returns labels for Moodle core competencies linked to a course module.
 *
 * @param int $courseid
 * @param int $cmid
 * @return string[]
 */
function format_selfstudy_get_cm_core_competency_labels(int $courseid, int $cmid): array {
    global $DB;

    if (!$courseid || !$cmid) {
        return [];
    }

    try {
        if (!get_config('core_competency', 'enabled')) {
            return [];
        }
    } catch (Throwable $exception) {
        return [];
    }

    $records = $DB->get_records_sql(
        "SELECT c.id, c.shortname, c.idnumber
           FROM {competency_modulecomp} mc
           JOIN {competency_coursecomp} cc
             ON cc.competencyid = mc.competencyid
            AND cc.courseid = :courseid
           JOIN {competency} c ON c.id = mc.competencyid
          WHERE mc.cmid = :cmid
       ORDER BY mc.sortorder ASC, c.shortname ASC",
        ['courseid' => $courseid, 'cmid' => $cmid]
    );

    $labels = [];
    foreach ($records as $record) {
        $shortname = format_string((string)$record->shortname, true);
        $idnumber = trim((string)$record->idnumber);
        $labels[] = $idnumber !== '' ? $idnumber . ' - ' . $shortname : $shortname;
    }

    return $labels;
}

/**
 * Returns stored selfstudy metadata for a course module.
 *
 * @param int $cmid
 * @return stdClass
 */
function format_selfstudy_get_cm_metadata(int $cmid): stdClass {
    global $DB;

    $empty = (object)[
        'learninggoal' => '',
        'durationminutes' => 0,
        'competencies' => '',
    ];
    if (!$cmid || !format_selfstudy_cmgoals_table_exists()) {
        return clone $empty;
    }

    $cache = &format_selfstudy_cm_metadata_cache();
    if (array_key_exists($cmid, $cache)) {
        return clone $cache[$cmid];
    }

    $record = $DB->get_record('format_selfstudy_cmgoals', ['cmid' => $cmid],
        'learninggoal, durationminutes, competencies', IGNORE_MISSING);
    $cache[$cmid] = (object)[
        'learninggoal' => $record ? (string)$record->learninggoal : '',
        'durationminutes' => $record ? (int)$record->durationminutes : 0,
        'competencies' => $record ? (string)$record->competencies : '',
    ];

    return clone $cache[$cmid];
}

/**
 * Saves or removes the learning goal for a course module.
 *
 * @param int $cmid
 * @param string $learninggoal
 */
function format_selfstudy_save_cm_learninggoal(int $cmid, string $learninggoal): void {
    $metadata = format_selfstudy_get_cm_metadata($cmid);
    $metadata->learninggoal = $learninggoal;
    format_selfstudy_save_cm_metadata($cmid, $metadata);
}

/**
 * Saves or removes selfstudy metadata for a course module.
 *
 * @param int $cmid
 * @param stdClass $metadata
 */
function format_selfstudy_save_cm_metadata(int $cmid, stdClass $metadata): void {
    global $DB;

    if (!$cmid || !format_selfstudy_cmgoals_table_exists()) {
        return;
    }

    $learninggoal = trim((string)($metadata->learninggoal ?? ''));
    $durationminutes = max(0, (int)($metadata->durationminutes ?? 0));
    $competencies = trim((string)($metadata->competencies ?? ''));
    $existing = $DB->get_record('format_selfstudy_cmgoals', ['cmid' => $cmid], '*', IGNORE_MISSING);
    if ($learninggoal === '' && $durationminutes === 0 && $competencies === '') {
        if ($existing) {
            $DB->delete_records('format_selfstudy_cmgoals', ['cmid' => $cmid]);
        }
        $cache = &format_selfstudy_cm_metadata_cache();
        $cache[$cmid] = (object)[
            'learninggoal' => '',
            'durationminutes' => 0,
            'competencies' => '',
        ];
        return;
    }

    $now = time();
    if ($existing) {
        $existing->learninggoal = $learninggoal;
        $existing->durationminutes = $durationminutes;
        $existing->competencies = $competencies;
        $existing->timemodified = $now;
        $DB->update_record('format_selfstudy_cmgoals', $existing);
    } else {
        $record = (object)[
            'cmid' => $cmid,
            'learninggoal' => $learninggoal,
            'durationminutes' => $durationminutes,
            'competencies' => $competencies,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $DB->insert_record('format_selfstudy_cmgoals', $record);
    }

    $cache = &format_selfstudy_cm_metadata_cache();
    $cache[$cmid] = (object)[
        'learninggoal' => $learninggoal,
        'durationminutes' => $durationminutes,
        'competencies' => $competencies,
    ];
}

/**
 * Returns an in-request cache for activity selfstudy metadata.
 *
 * @return array
 */
function &format_selfstudy_cm_metadata_cache(): array {
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

/**
 * Normalises a custom hex colour value.
 *
 * @param string $color
 * @return string
 */
function format_selfstudy_normalise_hex_color(string $color): string {
    $color = trim($color);
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        return strtolower($color);
    }

    return '#6f1ab1';
}
