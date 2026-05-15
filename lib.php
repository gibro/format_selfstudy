<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/course/format/lib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/filelib.php');

/**
 * Course format class for self-directed learning courses.
 */
class format_selfstudy extends core_courseformat\base {

    /** Maximum number of course contacts shown in the hero. */
    private const MAX_HERO_CONTACTS = 10;

    /**
     * Returns true because selfstudy courses are section based.
     *
     * @return bool
     */
    public function uses_sections() {
        return true;
    }

    /**
     * Returns the display name of a course section.
     *
     * Moodle core renderers call this method directly in editing mode. Prefer the
     * stored section name there as well, so section headings stay consistent with
     * the selfstudy overview cards.
     *
     * @param int|stdClass|section_info $section Section object or section number.
     * @return string
     */
    public function get_section_name($section) {
        $sectioninfo = $this->get_section($section);
        if ($sectioninfo && trim((string)$sectioninfo->name) !== '') {
            return format_string($sectioninfo->name, true,
                ['context' => context_course::instance($this->courseid)]);
        }

        return $this->get_default_section_name($section);
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
     * Supports Moodle's AJAX course editing features.
     *
     * @return stdClass
     */
    public function supports_ajax() {
        $ajaxsupport = new stdClass();
        $ajaxsupport->capable = true;
        return $ajaxsupport;
    }

    /**
     * Supports Moodle course content components in editing mode.
     *
     * @return bool
     */
    public function supports_components() {
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
        $path = $page->url ? $page->url->get_path(false) : '';
        if ($path === '/course/view.php') {
            $page->add_body_class('format-selfstudy-hide-courseindex');
        }
        if (!$this->is_learning_navigation_page($page)) {
            $page->add_body_class('format-selfstudy-hide-courseindex');
            return;
        }

        $options = $this->get_format_options();
        $avatarenabled = !empty($options['enableavatar']);
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
        $nextbuttoncolor = format_selfstudy_normalise_hex_color($options['nextbuttoncolor'] ?? $pathpointcolor,
            $pathpointcolor);

        $avatarconfig = $avatarenabled ?
            $this->get_avatar_marker_config($page, $OUTPUT, $USER) : ['imageurl' => '', 'label' => ''];

        if ($currentmodname === 'learningmap') {
            $page->requires->js_call_amd('format_selfstudy/navigation', 'init', [[
                'learningmapMode' => true,
                'activityUrls' => $this->get_ordered_learning_activity_urls(),
                'currentActivityId' => $currentactivityid,
                'currentCmId' => $currentcmid,
                'courseId' => (int)$this->courseid,
                'avatarEnabled' => $avatarenabled,
                'avatarImageUrl' => $avatarconfig['imageurl'],
                'avatarLabel' => $avatarconfig['label'],
                'currentStatusLabel' => get_string('learningpathstatuscurrent', 'format_selfstudy'),
                'competenciesLabel' => get_string('activitycompetencies', 'format_selfstudy'),
                'courseUrl' => (new moodle_url('/course/view.php', ['id' => $this->courseid]))->out(false),
                'courseLabel' => get_string('viewcourse', 'format_selfstudy'),
                'pathTitle' => get_string('learningpathcurrent', 'format_selfstudy'),
                'pathOutline' => $showpathui ? $pathoutline : [],
                'showPathOutline' => $showpathui,
                'pathPointColor' => $pathpointcolor,
                'nextButtonColor' => $nextbuttoncolor,
                'accessiblePathUrl' => $showpathui ?
                    (new moodle_url('/course/format/selfstudy/accessible_path.php',
                        ['id' => $this->courseid]))->out(false) : '',
                'accessiblePathLabel' => get_string('learningpathaccessibleview', 'format_selfstudy'),
            ]]);
            return;
        }

        $experiencehints = $this->get_experience_navigation_hints($currentcmid);
        if (!empty($experiencehints->mapurl)) {
            $mapurl = new moodle_url($experiencehints->mapurl);
        }
        if (!empty($experiencehints->mapbackgroundurl)) {
            $mapbackgroundurl = new moodle_url($experiencehints->mapbackgroundurl);
        }

        $showpathnavigation = $showpathui && !empty($pathoutline);
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
            'showNavigation' => $shownavigation || $showpathnavigation,
            'showPreviousButton' => !empty($options['shownavprevious']) || $showpathnavigation,
            'showMapButton' => !empty($options['shownavmap']),
            'showNextButton' => !empty($options['shownavnext']) || $showpathnavigation,
            'previousLabel' => $options['previousbuttonlabel'] ?? get_string('previousbuttonlabel_default', 'format_selfstudy'),
            'mapLabel' => $options['mapbuttonlabel'] ?? get_string('mapbuttonlabel_default', 'format_selfstudy'),
            'nextLabel' => $options['nextbuttonlabel'] ?? get_string('nextbuttonlabel_default', 'format_selfstudy'),
            'courseUrl' => (new moodle_url('/course/view.php', ['id' => $this->courseid]))->out(false),
            'courseLabel' => get_string('viewcourse', 'format_selfstudy'),
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
            'avatarEnabled' => $avatarenabled,
            'avatarImageUrl' => $avatarconfig['imageurl'],
            'avatarLabel' => $avatarconfig['label'],
            'pathTitle' => get_string('learningpathcurrent', 'format_selfstudy'),
            'pathOutline' => $showpathui ? $pathoutline : [],
            'showPathOutline' => $showpathui,
            'pathPointColor' => $pathpointcolor,
            'nextButtonColor' => $nextbuttoncolor,
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

            $authoringurl = new moodle_url('/course/format/selfstudy/authoring.php', ['id' => $course->id]);
            $editorurl = new moodle_url('/course/format/selfstudy/path_editor.php', ['id' => $course->id]);
            $selfstudynode = $courseadminnode->add(
                get_string('selfstudynavigation', 'format_selfstudy'),
                $authoringurl,
                navigation_node::TYPE_CONTAINER,
                null,
                'format_selfstudy',
                new pix_icon('i/settings', '')
            );
            $selfstudynode->set_force_into_more_menu(true);

            $selfstudynode->add(
                get_string('authoringworkflow', 'format_selfstudy'),
                $authoringurl,
                navigation_node::TYPE_SETTING,
                null,
                'format_selfstudy_authoring',
                new pix_icon('i/course', '')
            );

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
            'learnerview' => [
                'default' => 'base',
                'type' => PARAM_TEXT,
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
            unset($options['mainlearningmap']);
            unset($options['enablesectionmaps']);
            unset($options['enableavatar']);
            unset($options['defaultview']);
            unset($options['enabledashboard']);
            unset($options['enablelistview']);
            unset($options['learnerview']);
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
                file_prepare_draft_area($draftitemid, $context->id, 'format_selfstudy', 'sectionimage',
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
            $mform->setExpanded('selfstudycourseformatstart', true);
            $startintro = $mform->addElement('static', 'selfstudycourseformatstart_intro', '',
                get_string('courseformatsettingsstart_desc', 'format_selfstudy'));
            $heroimage = $mform->addElement('filemanager', 'selfstudyheroimage_filemanager',
                get_string('courseheroimage', 'format_selfstudy'), null, $fileoptions);
            $mform->addHelpButton('selfstudyheroimage_filemanager', 'courseheroimage', 'format_selfstudy');
            $mform->setDefault('selfstudyheroimage_filemanager', $draftitemid);
            $heroimagehint = $mform->addElement('static', 'selfstudyheroimage_hint', '',
                get_string('courseheroimage_hint', 'format_selfstudy'));
            $contactheading = $mform->addElement('static', 'selfstudycoursecontacts_heading', '',
                html_writer::div(
                    html_writer::tag('h4', get_string('coursecontacts', 'format_selfstudy')),
                    'format-selfstudy-settings-subsection'
                ));
            $contactintro = $mform->addElement('static', 'selfstudycoursecontacts_intro', '',
                get_string('coursecontactsintro', 'format_selfstudy'));

            $contactelements = [$contactheading, $contactintro];
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
            $mform->setExpanded('selfstudycourseformatgeneral', true);
            $generalintro = $mform->addElement('static', 'selfstudycourseformatgeneral_intro', '',
                get_string('courseformatsettingsgeneral_desc', 'format_selfstudy'));
            $designheader = $mform->addElement('header', 'selfstudycourseformatdesign',
                get_string('courseformatsettingsdesign', 'format_selfstudy'));
            $mform->setExpanded('selfstudycourseformatdesign', true);
            $designintro = $mform->addElement('static', 'selfstudycourseformatdesign_intro', '',
                get_string('courseformatsettingsdesign_desc', 'format_selfstudy'));
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
                    'var label=row.querySelector(".col-form-label");' .
                    'var element=row.querySelector(".felement");' .
                    'if(label){label.classList.add("col-md-3");}' .
                    'if(element){element.classList.add("col-md-9");element.classList.remove("col-md-3");}' .
                    '});' .
                    '});'
                ) .
                html_writer::div(
                    html_writer::tag('h4', get_string('courseformatsettingsdesigncolors', 'format_selfstudy')) .
                    html_writer::div(get_string('courseformatsettingsdesigncolors_desc', 'format_selfstudy'),
                        'format-selfstudy-settings-help'),
                    'format-selfstudy-settings-subsection'
                ));
            $designnavigationintro = $mform->addElement('static', 'selfstudycourseformatdesign_navigation', '',
                html_writer::div(
                    html_writer::tag('h4', get_string('courseformatsettingsdesignnavigation', 'format_selfstudy')) .
                    html_writer::div(get_string('courseformatsettingsdesignnavigation_desc', 'format_selfstudy'),
                        'format-selfstudy-settings-help'),
                    'format-selfstudy-settings-subsection'
                ));
            $experienceheader = $mform->addElement('header', 'selfstudycourseformatexperience',
                get_string('courseformatsettingsexperience', 'format_selfstudy'));
            $mform->setExpanded('selfstudycourseformatexperience', true);
            $experienceintro = $mform->addElement('static', 'selfstudycourseformatexperience_intro', '',
                get_string('courseformatsettingsexperience_desc', 'format_selfstudy'));
            $experiencecontrols = $mform->addElement('static', 'selfstudycourseformatexperience_controls', '',
                $this->render_course_experience_settings_fragment());

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
                array_merge([$startheader, $startintro, $heroimage, $heroimagehint], $contactelements,
                    [$generalheader, $generalintro]),
                $generalelements,
                [$designheader, $designintro, $designcolorsintro],
                $designcolorelements,
                [$designnavigationintro],
                $designnavigationelements,
                [$experienceheader, $experienceintro, $experiencecontrols]
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
                'format_selfstudy', 'sectionimage', (int)$dataobject->id,
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
        $this->save_course_experiences_from_request();

        return $changed;
    }

    /**
     * Renders installed learner experience controls inside the course settings form.
     *
     * @return string
     */
    protected function render_course_experience_settings_fragment(): string {
        $course = $this->get_course();
        $repository = new \format_selfstudy\local\experience_repository();
        $registry = new \format_selfstudy\local\experience_registry($repository);

        $entries = array_values($registry->get_course_experiences($course));

        $formatoptions = $this->get_format_options();
        $selectedlearnerview = (string)($formatoptions['learnerview'] ?? 'base');
        $availableviewvalues = ['base'];
        foreach ($entries as $entry) {
            if (!$entry->missing && $entry->status !== \format_selfstudy\local\experience_registry::STATUS_INCOMPATIBLE) {
                $availableviewvalues[] = 'experience:' . $entry->component;
            }
        }
        if (!in_array($selectedlearnerview, $availableviewvalues, true)) {
            $selectedlearnerview = 'base';
        }

        $output = html_writer::start_div('format-selfstudy-course-settings-experiences');
        $output .= html_writer::start_div('format-selfstudy-settings-card format-selfstudy-settings-card-primary');
        $output .= html_writer::tag('h4', get_string('learnerviewselection', 'format_selfstudy'));
        $output .= html_writer::div(get_string('learnerviewselection_help', 'format_selfstudy'),
            'format-selfstudy-settings-help');
        $output .= $this->render_course_experience_radio('base', get_string('learnerviewbase', 'format_selfstudy'),
            $selectedlearnerview === 'base');
        foreach ($entries as $entry) {
            if ($entry->missing || $entry->status === \format_selfstudy\local\experience_registry::STATUS_INCOMPATIBLE) {
                continue;
            }
            $viewvalue = 'experience:' . $entry->component;
            $output .= $this->render_course_experience_radio($viewvalue,
                get_string('learnerviewexperience', 'format_selfstudy', format_string($entry->name)),
                $selectedlearnerview === $viewvalue);
        }
        $output .= html_writer::end_div();

        if ($entries) {
            $output .= html_writer::start_div('format-selfstudy-settings-experience-grid');
            foreach ($entries as $index => $entry) {
                $output .= $this->render_course_experience_card($entry, $index);
            }
            $output .= html_writer::end_div();
        } else {
            $output .= html_writer::div(get_string('experiencenoneavailable', 'format_selfstudy'),
                'alert alert-info');
        }
        $output .= html_writer::end_div();

        return $output;
    }

    /**
     * Renders a learner view radio option.
     *
     * @param string $value
     * @param string $label
     * @param bool $checked
     * @return string
     */
    protected function render_course_experience_radio(string $value, string $label, bool $checked): string {
        return html_writer::div(
            html_writer::label(
                html_writer::empty_tag('input', [
                    'type' => 'radio',
                    'name' => 'selfstudy_learnerview',
                    'value' => $value,
                ] + ($checked ? ['checked' => 'checked'] : [])) .
                html_writer::span($label),
                ''
            ),
            'format-selfstudy-settings-choice'
        );
    }

    /**
     * Renders one installed or stored experience card for the course settings form.
     *
     * @param stdClass $entry
     * @param int $index
     * @return string
     */
    protected function render_course_experience_card(stdClass $entry, int $index): string {
        $configjson = json_encode($entry->config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($configjson === false) {
            $configjson = '{}';
        }

        $enabledattrs = [
            'type' => 'checkbox',
            'name' => 'selfstudy_experience_enabled[' . $index . ']',
            'value' => 1,
            'disabled' => 'disabled',
        ];
        if ((string)($this->get_format_options()['learnerview'] ?? 'base') === 'experience:' . $entry->component &&
                !$entry->missing) {
            $enabledattrs['checked'] = 'checked';
        }

        $statusclass = 'format-selfstudy-settings-status-' . clean_param($entry->status, PARAM_ALPHANUMEXT);
        $output = html_writer::start_div('format-selfstudy-settings-card format-selfstudy-settings-experience');
        $output .= html_writer::start_div('format-selfstudy-settings-card-head');
        $output .= html_writer::div(
            html_writer::tag('h4', format_string($entry->name)) .
            html_writer::div(s($entry->component), 'format-selfstudy-settings-component') .
            ($entry->description !== '' ? html_writer::div(format_text($entry->description, FORMAT_PLAIN),
                'format-selfstudy-settings-help') : ''),
            'format-selfstudy-settings-card-title'
        );
        $output .= html_writer::span(get_string('experiencestatus' . $entry->status, 'format_selfstudy'),
            'format-selfstudy-settings-status ' . $statusclass);
        $output .= html_writer::end_div();

        $output .= html_writer::start_div('format-selfstudy-settings-card-controls');
        $output .= html_writer::div(
            html_writer::label(
                html_writer::empty_tag('input', $enabledattrs) .
                html_writer::span(get_string('experienceenabled', 'format_selfstudy')),
                ''
            ),
            'format-selfstudy-settings-choice'
        );
        $output .= html_writer::label(get_string('sortorder', 'format_selfstudy'),
            'selfstudy-experience-sortorder-' . $index, false, ['class' => 'format-selfstudy-settings-label']);
        $output .= html_writer::empty_tag('input', [
            'type' => 'number',
            'name' => 'selfstudy_experience_sortorder[' . $index . ']',
            'id' => 'selfstudy-experience-sortorder-' . $index,
            'value' => $entry->sortorder,
            'class' => 'form-control format-selfstudy-settings-smallinput',
            'min' => 0,
            'step' => 1,
        ]);
        $output .= html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'selfstudy_experience_component[' . $index . ']',
            'value' => $entry->component,
        ]);
        $output .= html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'selfstudy_experience_configjson[' . $index . ']',
            'value' => $configjson,
        ]);
        $output .= html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'selfstudy_experience_configschema[' . $index . ']',
            'value' => $entry->schema,
        ]);
        $output .= html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'selfstudy_experience_missing[' . $index . ']',
            'value' => $entry->missing ? 1 : 0,
        ]);
        $output .= html_writer::end_div();

        if (!$entry->missing && $entry->configformclass !== '' && class_exists($entry->configformclass) &&
                method_exists($entry->configformclass, 'render')) {
            $output .= $entry->configformclass::render($this->get_course(), $entry->config,
                'selfstudy_experience_config_' . $index);
        }

        $output .= html_writer::end_div();
        return $output;
    }

    /**
     * Saves learner experience settings posted from the course settings form.
     */
    protected function save_course_experiences_from_request(): void {
        if (empty($this->courseid)) {
            return;
        }

        $components = optional_param_array('selfstudy_experience_component', [], PARAM_COMPONENT);
        if (!$components) {
            return;
        }

        $sortorders = optional_param_array('selfstudy_experience_sortorder', [], PARAM_INT);
        $configs = optional_param_array('selfstudy_experience_configjson', [], PARAM_RAW);
        $schemas = optional_param_array('selfstudy_experience_configschema', [], PARAM_INT);
        $missing = optional_param_array('selfstudy_experience_missing', [], PARAM_BOOL);
        $learnerview = optional_param('selfstudy_learnerview', 'base', PARAM_TEXT);

        $repository = new \format_selfstudy\local\experience_repository();
        foreach ($components as $index => $component) {
            $config = json_decode((string)($configs[$index] ?? '{}'), true);
            if (!is_array($config) || json_last_error() !== JSON_ERROR_NONE) {
                $config = [];
            }
            $configformclass = $this->get_experience_config_form_class($component);
            if ($configformclass && method_exists($configformclass, 'get_config_from_request')) {
                $config = $configformclass::get_config_from_request('selfstudy_experience_config_' . $index, $config);
            }

            $ismissing = !empty($missing[$index]);
            $viewvalue = 'experience:' . $component;
            $repository->save_course_experience((int)$this->courseid, $component, $config,
                !$ismissing && $learnerview === $viewvalue, (int)($sortorders[$index] ?? $index),
                max(1, (int)($schemas[$index] ?? 1)), $ismissing);
        }

        $validviews = array_merge(['base'], array_map(static function(string $component): string {
            return 'experience:' . $component;
        }, $components));
        if (!in_array($learnerview, $validviews, true)) {
            $learnerview = 'base';
        }
        parent::update_course_format_options(['learnerview' => $learnerview]);
    }

    /**
     * Returns the config form class for an experience component.
     *
     * @param string $component
     * @return string
     */
    private function get_experience_config_form_class(string $component): string {
        $component = clean_param($component, PARAM_COMPONENT);
        if (strpos($component, 'selfstudyexperience_') !== 0) {
            return '';
        }

        $name = clean_param(substr($component, strlen('selfstudyexperience_')), PARAM_PLUGIN);
        if ($name === '') {
            return '';
        }

        $class = '\\' . $component . '\\config_form';
        if (!class_exists($class)) {
            $path = __DIR__ . '/experience/' . $name . '/classes/config_form.php';
            if (is_readable($path)) {
                require_once($path);
            }
        }

        return class_exists($class) ? $class : '';
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
        if (isset($data['learnerview'])) {
            $data['learnerview'] = clean_param((string)$data['learnerview'], PARAM_TEXT);
            if ($data['learnerview'] === '') {
                $data['learnerview'] = 'base';
            }
        }
        if (isset($data['pathpointcolor'])) {
            $data['pathpointcolor'] = format_selfstudy_normalise_hex_color((string)$data['pathpointcolor']);
        }
        if (isset($data['nextbuttoncolor'])) {
            $fallback = format_selfstudy_normalise_hex_color((string)($data['pathpointcolor'] ?? '#6f1ab1'));
            $data['nextbuttoncolor'] = format_selfstudy_normalise_hex_color((string)$data['nextbuttoncolor'],
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

            unset($options['sectionmap']);
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
    if ($context->contextlevel !== CONTEXT_COURSE || !in_array($filearea, ['heroimage', 'sectionimage'], true)) {
        return false;
    }
    if (!has_capability('moodle/course:view', $context)) {
        return false;
    }

    $filename = array_pop($args);
    $itemid = $filearea === 'sectionimage' ? (int)array_shift($args) : 0;
    if (!$filename || $filename === '.') {
        return false;
    }

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'format_selfstudy', $filearea, $itemid, '/', $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
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
 * Returns the configured section overview image URL.
 *
 * @param stdClass $course
 * @param int $sectionid
 * @return moodle_url|null
 */
function format_selfstudy_get_section_image_url(stdClass $course, int $sectionid): ?moodle_url {
    if (!$sectionid) {
        return null;
    }

    $context = context_course::instance($course->id);
    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'format_selfstudy', 'sectionimage', $sectionid,
        'sortorder, id', false);
    $file = reset($files);
    if (!$file) {
        return null;
    }

    return moodle_url::make_pluginfile_url($context->id, 'format_selfstudy', 'sectionimage', $sectionid, '/',
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
function format_selfstudy_normalise_hex_color(string $color, string $fallback = '#6f1ab1'): string {
    $color = trim($color);
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        return strtolower($color);
    }

    return preg_match('/^#[0-9a-fA-F]{6}$/', $fallback) ? strtolower($fallback) : '#6f1ab1';
}
