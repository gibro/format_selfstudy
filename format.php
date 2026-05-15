<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/completionlib.php');

global $OUTPUT, $USER;

if (empty($FORMAT_SELFSTUDY_FUNCTIONS_ONLY)) {
$format = course_get_format($course);
$formatoptions = $format->get_format_options();
$course = $format->get_course();
if ($PAGE->user_is_editing()) {
    course_create_sections_if_missing($course, 0);
    $renderer = $PAGE->get_renderer('format_selfstudy');
    if (isset($displaysection) && !is_null($displaysection)) {
        $format->set_sectionnum($displaysection);
    }
    $outputclass = $format->get_output_classname('content');
    $output = new $outputclass($format);
    echo $renderer->render($output);
    return;
}
$pathpointcolor = format_selfstudy_normalise_hex_color($formatoptions['pathpointcolor'] ?? '#6f1ab1');
$nextbuttoncolor = format_selfstudy_normalise_hex_color($formatoptions['nextbuttoncolor'] ?? $pathpointcolor,
    $pathpointcolor);
$learnerview = (string)($formatoptions['learnerview'] ?? 'base');
$enabledashboard = $learnerview === 'base';
$enablelistview = false;
$showlockedactivities = !array_key_exists('showlockedactivities', $formatoptions) ||
    !empty($formatoptions['showlockedactivities']);
$modinfo = get_fast_modinfo($course);
$sections = $modinfo->get_section_info_all();
$completion = new completion_info($course);
$learningmapconfig = format_selfstudy_get_learningmap_legacy_config($course, $format, $modinfo, $sections);
$learningsummaries = format_selfstudy_get_learning_summaries($course, $format, $modinfo, $sections, $completion,
    $learningmapconfig);
$coursecontext = context_course::instance($course->id);
$canmanagecourse = has_capability('moodle/course:update', $coursecontext);
$caneditsections = $PAGE->user_is_editing() && $canmanagecourse;
$courserenderer = $PAGE->get_renderer('core', 'course');

$mainmapcm = format_selfstudy_get_learningmap_main_cm($course, $modinfo, $learningmapconfig);

$nextcm = format_selfstudy_find_next_cm($learningsummaries);
$requiredopen = format_selfstudy_count_sections($learningsummaries, 'required', false);
$requiredcomplete = format_selfstudy_count_sections($learningsummaries, 'required', true);
$pathrepository = new \format_selfstudy\local\path_repository();
$learningpaths = $pathrepository->get_paths((int)$course->id, true);
$hasavailablepathchoices = count($learningpaths) > 1 || (!empty($formatoptions['allowpersonalpaths']) &&
    format_selfstudy_has_personal_path_choices($course, $learningpaths, $learningsummaries, $pathrepository));
$showpathui = !empty($formatoptions['allowpersonalpaths']) || count($learningpaths) > 1;
$baseview = \format_selfstudy\local\base_view::create($course, (int)$USER->id);
$activepath = $baseview->path;
$activepathprogress = $baseview->progress;
$activepathoutline = $baseview->outline;
$visibleactivepathoutline = format_selfstudy_filter_locked_outline($activepathoutline, $showlockedactivities);
$pathstarted = format_selfstudy_path_has_started($activepathprogress, $visibleactivepathoutline);
$pathdurationminutes = format_selfstudy_sum_path_duration_minutes($visibleactivepathoutline);
$remainingdurationminutes = format_selfstudy_sum_remaining_path_duration_minutes($visibleactivepathoutline);
$pathstationcount = format_selfstudy_count_path_stations($visibleactivepathoutline);
$heroimageurl = format_selfstudy_get_hero_image_url($course);
$coursecontacts = format_selfstudy_get_course_contacts((int)$course->id);
$continueurl = format_selfstudy_get_continue_url($course, $mainmapcm, $activepathprogress, $nextcm);
$continuelabel = !empty($activepathprogress->total) && $activepathprogress->complete >= $activepathprogress->total ?
    get_string('learningpathcompleteoverview', 'format_selfstudy') :
    get_string('startlearning', 'format_selfstudy');

if ($learnerview !== 'base') {
    echo format_selfstudy_render_selected_experience($course, $baseview, $learnerview, $canmanagecourse);
    return;
}

if ($enabledashboard) {
    echo html_writer::start_div('format-selfstudy-dashboard', [
        'style' => '--format-selfstudy-path-color: ' . $pathpointcolor .
            '; --format-selfstudy-next-button-color: ' . $nextbuttoncolor,
    ]);

    echo html_writer::start_tag('section', ['class' => 'format-selfstudy-coursehero']);
    echo html_writer::start_div('format-selfstudy-coursehero-main');
    echo html_writer::span(get_string($pathstarted ? 'courseherokickerstarted' : 'courseherokickerstart',
        'format_selfstudy'), 'format-selfstudy-coursehero-kicker');
    echo html_writer::tag('h2', format_string($course->fullname), ['class' => 'format-selfstudy-coursehero-title']);
    if (!empty($course->summary)) {
        echo html_writer::div(format_text($course->summary, $course->summaryformat ?? FORMAT_HTML),
            'format-selfstudy-coursehero-summary');
    }

    echo html_writer::start_div('format-selfstudy-actions format-selfstudy-coursehero-actions');
    if ($pathstarted && $continueurl) {
        echo html_writer::link(
            $continueurl,
            $continuelabel,
            ['class' => 'btn btn-primary format-selfstudy-coursehero-primary']
        );
    }
    if ($hasavailablepathchoices) {
        echo html_writer::link(
            new moodle_url('/course/format/selfstudy/personal_path.php', ['id' => $course->id]),
            get_string('learningpathsavailablebutton', 'format_selfstudy'),
            ['class' => 'btn btn-secondary']
        );
    }
    echo html_writer::end_div();
    echo format_selfstudy_render_coursehero_contacts($course, $coursecontacts);
    echo html_writer::end_div();

    echo format_selfstudy_render_coursehero_image($heroimageurl, $course);

    echo html_writer::start_div('format-selfstudy-coursehero-facts');
    echo format_selfstudy_render_coursehero_fact(get_string('courseheroduration', 'format_selfstudy'),
        format_selfstudy_format_duration($pathdurationminutes));
    echo format_selfstudy_render_coursehero_fact(get_string('courseheroremainingduration', 'format_selfstudy'),
        $pathdurationminutes > 0 && $remainingdurationminutes <= 0 ?
            get_string('courseherodurationcomplete', 'format_selfstudy') :
            format_selfstudy_format_duration($remainingdurationminutes));
    echo format_selfstudy_render_coursehero_fact(get_string('courseherostations', 'format_selfstudy'),
        (string)$pathstationcount);
    echo format_selfstudy_render_coursehero_fact(get_string('courseheroprogress', 'format_selfstudy'),
        $activepathprogress ? get_string('learningpathprogresscount', 'format_selfstudy', (object)[
            'complete' => $activepathprogress->complete,
            'total' => $activepathprogress->total,
            'percentage' => $activepathprogress->percentage,
        ]) : get_string('statusnotstarted', 'format_selfstudy'));
    echo format_selfstudy_render_coursehero_fact(get_string('courseherorequired', 'format_selfstudy'),
        get_string('courseherorequiredvalue', 'format_selfstudy', (object)[
            'complete' => $requiredcomplete,
            'open' => $requiredopen,
        ]));
    echo html_writer::end_div();
    echo html_writer::end_tag('section');

    echo format_selfstudy_render_section_overview($course, $learningsummaries);

    if ($caneditsections) {
        echo html_writer::start_div('format-selfstudy-actions');
        echo html_writer::link(
            new moodle_url('/course/format/selfstudy/teacher_analytics.php', ['id' => $course->id]),
            get_string('teacheranalytics', 'format_selfstudy'),
            ['class' => 'btn btn-secondary']
        );
        echo html_writer::link(
            new moodle_url('/course/format/selfstudy/path_editor.php', ['id' => $course->id]),
            get_string('learningpatheditor', 'format_selfstudy'),
            ['class' => 'btn btn-secondary']
        );
        echo html_writer::end_div();
    }

    echo html_writer::end_div();
}

if ($enablelistview) {
    echo html_writer::start_div('format-selfstudy-sections');
    echo html_writer::tag('h2', get_string('learningpathcoursesections', 'format_selfstudy'),
        ['class' => 'format-selfstudy-sections-title']);

    foreach ($learningsummaries as $summary) {
        $section = $summary->section;
        $pathkind = $summary->pathkind;
        $sectiontitle = format_selfstudy_get_section_title($course, $section);
        $sectiontitleid = 'format-selfstudy-section-title-' . $section->id;

        echo html_writer::start_tag('section', [
            'class' => 'section main clearfix format-selfstudy-section format-selfstudy-section-' . $summary->statuskey,
            'id' => 'section-' . $section->section,
            'data-section' => $section->section,
            'data-sectionid' => $section->id,
            'aria-labelledby' => $sectiontitleid,
        ]);

        echo html_writer::start_div('format-selfstudy-sectionheader');
        echo html_writer::tag('h3', $sectiontitle, [
            'class' => 'format-selfstudy-sectiontitle',
            'id' => $sectiontitleid,
        ]);
        echo html_writer::start_div('format-selfstudy-sectionmeta');
        echo html_writer::start_div('format-selfstudy-badges');
        echo html_writer::span(
            get_string($pathkind === 'optional' ? 'optional' : 'required', 'format_selfstudy'),
            'format-selfstudy-badge format-selfstudy-badge-' . ($pathkind === 'optional' ? 'optional' : 'required')
        );
        echo html_writer::span(
            $summary->statuslabel,
            'format-selfstudy-badge'
        );
        if ($summary->sectionmapcm) {
            echo html_writer::link(
                new moodle_url('/mod/learningmap/view.php', ['id' => $summary->sectionmapcm->id]),
                get_string('submap', 'format_selfstudy'),
                ['class' => 'format-selfstudy-badge']
            );
        }
        echo html_writer::end_div();
        if ($caneditsections) {
            echo format_selfstudy_render_section_action_menu($section, $sectiontitle);
        }
        echo html_writer::end_div();
        echo html_writer::end_div();

        if ($summary->learninggoal !== '') {
            echo html_writer::div(format_text($summary->learninggoal, FORMAT_PLAIN), 'format-selfstudy-goal');
        }

        if ($summary->sectionmapcm) {
            echo html_writer::div(
                html_writer::link(
                    new moodle_url('/mod/learningmap/view.php', ['id' => $summary->sectionmapcm->id]),
                    get_string('opensubmap', 'format_selfstudy'),
                    ['class' => 'btn btn-secondary btn-sm']
                ),
                'format-selfstudy-sectionactions'
            );
        }

        echo html_writer::start_div('format-selfstudy-activities');
        echo format_selfstudy_render_standard_cm_list(
            $course,
            $section,
            true,
            $completion,
            $caneditsections,
            $showlockedactivities
        );
        echo html_writer::end_div();

        if ($caneditsections) {
            echo $courserenderer->course_section_add_cm_control($course, $section->section, $section->section);
        }

        echo html_writer::end_tag('section');
    }

    echo html_writer::end_div();
}
}

/**
 * Returns the visible section title.
 *
 * @param stdClass $course
 * @param section_info $section
 * @return string
 */
function format_selfstudy_get_section_title(stdClass $course, section_info $section): string {
    $customname = trim((string)$section->name);
    if ($customname !== '') {
        return format_string($customname, true, ['context' => context_course::instance($course->id)]);
    }

    return get_section_name($course, $section);
}

/**
 * Renders one compact course hero fact.
 *
 * @param string $label
 * @param string $value
 * @return string
 */
function format_selfstudy_render_coursehero_fact(string $label, string $value): string {
    return html_writer::div(
        html_writer::div(s($label), 'format-selfstudy-coursehero-factlabel') .
        html_writer::div(s($value), 'format-selfstudy-coursehero-factvalue'),
        'format-selfstudy-coursehero-fact'
    );
}

/**
 * Renders the course hero image slot.
 *
 * @param moodle_url|null $imageurl
 * @param stdClass $course
 * @return string
 */
function format_selfstudy_render_coursehero_image(?moodle_url $imageurl, stdClass $course): string {
    if ($imageurl) {
        return html_writer::div(
            html_writer::empty_tag('img', [
                'src' => $imageurl->out(false),
                'alt' => '',
                'loading' => 'lazy',
            ]),
            'format-selfstudy-coursehero-image'
        );
    }

    return html_writer::div(
        html_writer::span(format_string($course->shortname ?: $course->fullname)),
        'format-selfstudy-coursehero-image format-selfstudy-coursehero-image-fallback',
        ['aria-hidden' => 'true']
    );
}

/**
 * Renders configured course contact persons in the hero.
 *
 * @param stdClass $course
 * @param stdClass[] $contacts
 * @return string
 */
function format_selfstudy_render_coursehero_contacts(stdClass $course, array $contacts): string {
    global $OUTPUT;

    if (!$contacts) {
        return '';
    }

    $rolelabels = format_selfstudy_get_course_contact_role_labels();
    $items = [];
    foreach (array_slice($contacts, 0, 10) as $contact) {
        $roles = array_values(array_filter(array_map('trim', explode(',', (string)$contact->roles))));
        $badges = [];
        foreach ($roles as $role) {
            if (isset($rolelabels[$role])) {
                $badges[] = html_writer::span($rolelabels[$role], 'format-selfstudy-coursecontact-role');
            }
        }

        $profileurl = new moodle_url('/user/view.php', ['id' => (int)$contact->userid, 'course' => (int)$course->id]);
        $picture = $OUTPUT->user_picture($contact, [
            'size' => 48,
            'courseid' => (int)$course->id,
            'link' => false,
            'class' => 'format-selfstudy-coursecontact-avatar',
        ]);

        $items[] = html_writer::link($profileurl,
            $picture .
            html_writer::span(fullname($contact), 'format-selfstudy-coursecontact-name') .
            html_writer::span(implode('', $badges), 'format-selfstudy-coursecontact-roles'),
            ['class' => 'format-selfstudy-coursecontact']
        );
    }

    return html_writer::div(
        html_writer::div(get_string('coursecontacts', 'format_selfstudy'),
            'format-selfstudy-coursecontacts-label') .
        html_writer::div(implode('', $items), 'format-selfstudy-coursecontacts-list'),
        'format-selfstudy-coursecontacts'
    );
}

/**
 * Renders a visual overview of Moodle sections below the course hero.
 *
 * @param stdClass $course
 * @param stdClass[] $summaries
 * @return string
 */
function format_selfstudy_render_section_overview(stdClass $course, array $summaries): string {
    $cards = [];
    foreach ($summaries as $summary) {
        $section = $summary->section;
        if ((int)$section->section === 0) {
            continue;
        }

        $title = format_selfstudy_get_section_title($course, $section);
        $imageurl = format_selfstudy_get_section_image_url($course, (int)$section->id);
        $activitycount = count(array_filter($summary->cms, static function(cm_info $cm): bool {
            return format_selfstudy_is_learning_activity($cm);
        }));
        $kindlabel = get_string($summary->pathkind === 'optional' ? 'optional' : 'required', 'format_selfstudy');

        $media = $imageurl ?
            html_writer::empty_tag('img', [
                'src' => $imageurl->out(false),
                'alt' => '',
                'loading' => 'lazy',
            ]) :
            html_writer::span(substr($title, 0, 1), 'format-selfstudy-sectionoverview-initial',
                ['aria-hidden' => 'true']);

        $cards[] = html_writer::tag('article',
            html_writer::div($media, 'format-selfstudy-sectionoverview-media' .
                ($imageurl ? '' : ' format-selfstudy-sectionoverview-media-fallback')) .
            html_writer::div(
                html_writer::tag('h3', $title) .
                html_writer::div(
                    html_writer::span($kindlabel, 'format-selfstudy-sectionoverview-chip') .
                    html_writer::span($summary->statuslabel, 'format-selfstudy-sectionoverview-status'),
                    'format-selfstudy-sectionoverview-meta'
                ) .
                ($summary->learninggoal !== '' ? html_writer::div(format_text($summary->learninggoal, FORMAT_PLAIN),
                    'format-selfstudy-sectionoverview-goal') : '') .
                html_writer::div(get_string('sectionoverviewactivities', 'format_selfstudy', $activitycount),
                    'format-selfstudy-sectionoverview-count'),
                'format-selfstudy-sectionoverview-body'
            ),
            ['class' => 'format-selfstudy-sectionoverview-card']
        );
    }

    if (!$cards) {
        return '';
    }

    return html_writer::tag('section',
        html_writer::div(
            html_writer::tag('h2', get_string('sectionoverview', 'format_selfstudy')) .
            html_writer::div('', 'format-selfstudy-sectionoverview-accent') .
            html_writer::div(get_string('sectionoverviewintro', 'format_selfstudy'),
                'format-selfstudy-sectionoverview-intro'),
            'format-selfstudy-sectionoverview-head'
        ) .
        html_writer::div(implode('', $cards), 'format-selfstudy-sectionoverview-grid'),
        ['class' => 'format-selfstudy-sectionoverview']
    );
}

/**
 * Returns whether the learner has started their visible path.
 *
 * @param stdClass|null $progress
 * @param stdClass[] $outline
 * @return bool
 */
function format_selfstudy_path_has_started(?stdClass $progress, array $outline): bool {
    if (!empty($progress->complete)) {
        return true;
    }

    foreach ($outline as $entry) {
        if (in_array((string)($entry->status ?? ''), ['started', 'review', 'complete'], true)) {
            return true;
        }
    }

    return false;
}

/**
 * Counts visible station entries in a path outline.
 *
 * @param stdClass[] $outline
 * @return int
 */
function format_selfstudy_count_path_stations(array $outline): int {
    $count = 0;
    foreach ($outline as $entry) {
        if (!empty($entry->cmid)) {
            $count++;
        }
    }
    return $count;
}

/**
 * Sums station duration metadata for visible path entries.
 *
 * @param stdClass[] $outline
 * @return int
 */
function format_selfstudy_sum_path_duration_minutes(array $outline): int {
    $minutes = 0;
    $seen = [];
    foreach ($outline as $entry) {
        $cmid = (int)($entry->cmid ?? 0);
        if (!$cmid || isset($seen[$cmid])) {
            continue;
        }
        $seen[$cmid] = true;
        $minutes += max(0, (int)format_selfstudy_get_cm_metadata($cmid)->durationminutes);
    }
    return $minutes;
}

/**
 * Sums station duration metadata for path entries that are not completed yet.
 *
 * @param stdClass[] $outline
 * @return int
 */
function format_selfstudy_sum_remaining_path_duration_minutes(array $outline): int {
    $minutes = 0;
    $seen = [];
    foreach ($outline as $entry) {
        if (($entry->status ?? '') === 'complete') {
            continue;
        }

        $cmid = (int)($entry->cmid ?? 0);
        if (!$cmid || isset($seen[$cmid])) {
            continue;
        }
        $seen[$cmid] = true;
        $minutes += max(0, (int)format_selfstudy_get_cm_metadata($cmid)->durationminutes);
    }
    return $minutes;
}

/**
 * Formats a duration value for the learner dashboard.
 *
 * @param int $minutes
 * @return string
 */
function format_selfstudy_format_duration(int $minutes): string {
    if ($minutes <= 0) {
        return get_string('courseherodurationunknown', 'format_selfstudy');
    }
    if ($minutes < 60) {
        return get_string('courseherodurationminutes', 'format_selfstudy', $minutes);
    }

    $hours = intdiv($minutes, 60);
    $remaining = $minutes % 60;
    if ($remaining === 0) {
        return get_string('courseherodurationhours', 'format_selfstudy', $hours);
    }

    return get_string('courseherodurationhoursminutes', 'format_selfstudy', (object)[
        'hours' => $hours,
        'minutes' => $remaining,
    ]);
}

/**
 * Renders the main learning map notice.
 *
 * @param cm_info|null $mainmapcm
 * @return string
 */
function format_selfstudy_render_map_notice(?cm_info $mainmapcm): string {
    $output = html_writer::start_div('format-selfstudy-mapnotice');
    if ($mainmapcm) {
        $output .= html_writer::tag('strong', get_string('mainlearningmap', 'format_selfstudy') . ': ');
        $output .= html_writer::link(
            new moodle_url('/mod/learningmap/view.php', ['id' => $mainmapcm->id]),
            format_string($mainmapcm->name)
        );
    } else {
        $output .= html_writer::span(get_string('nolearningmap', 'format_selfstudy'));
    }
    $output .= html_writer::end_div();
    return $output;
}

/**
 * Renders optional experience entry points for the course dashboard.
 *
 * @param stdClass $course
 * @param stdClass $baseview
 * @param string[] $excludedcomponents
 * @return string
 */
function format_selfstudy_render_experience_zone(stdClass $course, stdClass $baseview,
        array $excludedcomponents = []): string {
    try {
        $registry = new \format_selfstudy\local\experience_registry();
        $entries = $registry->get_renderable_experiences($course, $baseview);
    } catch (Throwable $exception) {
        debugging('Selfstudy experience registry failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
        return '';
    }

    if (!$entries) {
        return '';
    }

    $items = '';
    foreach ($entries as $entry) {
        if (in_array($entry->component, $excludedcomponents, true)) {
            continue;
        }
        try {
            $html = $entry->renderer->render_course_entry($course, $baseview, $entry->config);
        } catch (Throwable $exception) {
            debugging('Selfstudy experience render failed for ' . $entry->component . ': ' .
                $exception->getMessage(), DEBUG_DEVELOPER);
            continue;
        }
        if (trim($html) !== '') {
            $componentclass = clean_param(str_replace('_', '-', $entry->component), PARAM_ALPHANUMEXT);
            $items .= html_writer::div($html, 'format-selfstudy-experience format-selfstudy-experience-' .
                $componentclass);
        }
    }

    if ($items === '') {
        return '';
    }

    return html_writer::div($items, 'format-selfstudy-experiences',
        ['aria-label' => get_string('experiencezone', 'format_selfstudy')]);
}

/**
 * Renders the single selected learner experience.
 *
 * @param stdClass $course
 * @param stdClass $baseview
 * @param string $learnerview
 * @param bool $canedit
 * @return string
 */
function format_selfstudy_render_selected_experience(stdClass $course, stdClass $baseview, string $learnerview,
        bool $canedit = false): string {
    if (strpos($learnerview, 'experience:') !== 0) {
        return '';
    }

    $component = clean_param(substr($learnerview, strlen('experience:')), PARAM_COMPONENT);
    if ($component === '') {
        return '';
    }

    try {
        $registry = new \format_selfstudy\local\experience_registry();
        foreach ($registry->get_course_experiences($course, $baseview) as $entry) {
            if ($entry->component !== $component) {
                continue;
            }

            if ($entry->status !== \format_selfstudy\local\experience_registry::STATUS_AVAILABLE ||
                    !$entry->renderer instanceof \format_selfstudy\local\experience_renderer_interface) {
                return $canedit ? html_writer::div(get_string('experienceselectedunavailable', 'format_selfstudy'),
                    'alert alert-warning') : '';
            }

            $html = $entry->renderer->render_course_entry($course, $baseview, $entry->config);
            if (trim($html) === '') {
                return $canedit ? html_writer::div(get_string('experienceselectedempty', 'format_selfstudy'),
                    'alert alert-warning') : '';
            }

            return html_writer::div($html, 'format-selfstudy-selected-experience');
        }
    } catch (Throwable $exception) {
        debugging('Selfstudy selected experience failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
    }

    return $canedit ? html_writer::div(get_string('experienceselectedmissing', 'format_selfstudy'),
        'alert alert-warning') : '';
}

/**
 * Returns legacy Learningmap settings as a plain config object.
 *
 * @param stdClass $course
 * @param core_courseformat\base|null $format
 * @param course_modinfo|null $modinfo
 * @param section_info[]|null $sections
 * @return stdClass|null
 */
function format_selfstudy_get_learningmap_legacy_config(stdClass $course, ?core_courseformat\base $format = null,
        ?course_modinfo $modinfo = null, ?array $sections = null): ?stdClass {
    $format = $format ?? course_get_format($course);
    $options = $format->get_format_options();
    $mainmapcmid = (int)($options['mainlearningmap'] ?? 0);
    $sectionmapsenabled = !empty($options['enablesectionmaps']);
    $sectionmaps = [];

    if ($sectionmapsenabled) {
        $modinfo = $modinfo ?? get_fast_modinfo($course);
        $sections = $sections ?? $modinfo->get_section_info_all();
        foreach ($sections as $section) {
            if (empty($section->id) || !$section->uservisible) {
                continue;
            }
            $sectionoptions = $format->get_format_options($section);
            $cmid = (int)($sectionoptions['sectionmap'] ?? 0);
            if ($cmid > 0) {
                $sectionmaps[(int)$section->id] = $cmid;
            }
        }
    }

    if ($mainmapcmid <= 0 && empty($sectionmaps)) {
        return null;
    }

    return (object)[
        'mainmapcmid' => $mainmapcmid,
        'sectionmaps' => (object)$sectionmaps,
        'sectionmapsenabled' => $sectionmapsenabled,
        'avatarenabled' => !empty($options['enableavatar']),
    ];
}

/**
 * Returns the visible configured main Learningmap CM.
 *
 * @param stdClass $course
 * @param course_modinfo $modinfo
 * @param stdClass|null $config
 * @return cm_info|null
 */
function format_selfstudy_get_learningmap_main_cm(stdClass $course, course_modinfo $modinfo,
        ?stdClass $config = null): ?cm_info {
    if ($config === null) {
        $config = format_selfstudy_get_learningmap_legacy_config($course, null, $modinfo);
    }

    return format_selfstudy_get_learningmap_cm($modinfo, (int)($config->mainmapcmid ?? 0));
}

/**
 * Returns the visible configured section Learningmap CM.
 *
 * @param stdClass $course
 * @param course_modinfo $modinfo
 * @param int $sectionid
 * @param stdClass|null $config
 * @return cm_info|null
 */
function format_selfstudy_get_learningmap_section_cm(stdClass $course, course_modinfo $modinfo, int $sectionid,
        ?stdClass $config = null): ?cm_info {
    if ($config === null) {
        $config = format_selfstudy_get_learningmap_legacy_config($course, null, $modinfo);
    }

    if (empty($config->sectionmapsenabled) || empty($config->sectionmaps) || !$sectionid) {
        return null;
    }

    $sectionmaps = (array)$config->sectionmaps;
    return format_selfstudy_get_learningmap_cm($modinfo, (int)($sectionmaps[$sectionid] ?? 0));
}

/**
 * Renders anonymised teacher analytics.
 *
 * @param stdClass $course
 * @param core_courseformat\base $format
 * @return string
 */
function format_selfstudy_render_teacher_analytics(stdClass $course, core_courseformat\base $format): string {
    $report = \format_selfstudy\local\teacher_analytics::get_course_report($course, $format);

    $output = html_writer::start_tag('section', ['class' => 'format-selfstudy-teacheranalytics']);
    $output .= html_writer::tag('h3', get_string('teacheranalytics', 'format_selfstudy'));
    $output .= html_writer::div(get_string('teacheranalyticsintro', 'format_selfstudy', $report->enrolled),
        'text-muted');
    $output .= html_writer::start_div('format-selfstudy-teacheranalytics-grid');
    $output .= format_selfstudy_render_teacher_analytics_list(
        get_string('teacheranalyticsstartedsections', 'format_selfstudy'),
        $report->frequentstartedsections,
        'started'
    );
    $output .= format_selfstudy_render_teacher_analytics_list(
        get_string('teacheranalyticsdropoffs', 'format_selfstudy'),
        $report->dropoffsections,
        'incomplete'
    );
    $output .= format_selfstudy_render_teacher_analytics_list(
        get_string('teacheranalyticsoptionalsections', 'format_selfstudy'),
        $report->selectedoptionalsections,
        'selected'
    );
    $output .= format_selfstudy_render_teacher_analytics_list(
        get_string('teacheranalyticsopenrequired', 'format_selfstudy'),
        $report->openrequiredstations,
        'open'
    );
    $output .= format_selfstudy_render_teacher_analytics_list(
        get_string('teacheranalyticsbottlenecks', 'format_selfstudy'),
        $report->bottlenecks,
        'bottleneck'
    );
    $output .= html_writer::end_div();
    $output .= html_writer::end_tag('section');

    return $output;
}

/**
 * Renders one analytics list.
 *
 * @param string $title
 * @param stdClass[] $items
 * @param string $field
 * @return string
 */
function format_selfstudy_render_teacher_analytics_list(string $title, array $items, string $field): string {
    $output = html_writer::start_div('format-selfstudy-teacheranalytics-panel');
    $output .= html_writer::tag('h4', $title);

    if (!$items) {
        $output .= html_writer::div(get_string('teacheranalyticsnone', 'format_selfstudy'), 'text-muted');
        $output .= html_writer::end_div();
        return $output;
    }

    $rows = [];
    foreach ($items as $item) {
        $label = s($item->title);
        if (!empty($item->modname)) {
            $label .= html_writer::div(s($item->modname), 'text-muted small');
        }
        $rows[] = html_writer::tag('li',
            html_writer::span((string)(int)$item->{$field}, 'format-selfstudy-teacheranalytics-count') .
            html_writer::span($label, 'format-selfstudy-teacheranalytics-title')
        );
    }

    $output .= html_writer::tag('ol', implode('', $rows), ['class' => 'format-selfstudy-teacheranalytics-list']);
    $output .= html_writer::end_div();
    return $output;
}

/**
 * Renders the standard Moodle activity list for a section.
 *
 * @param stdClass $course
 * @param section_info $section
 * @param bool $showstatus
 * @param completion_info|null $completion
 * @param bool $showactions
 * @return string
 */
function format_selfstudy_render_standard_cm_list(stdClass $course, section_info $section, bool $showstatus = false,
        ?completion_info $completion = null, bool $showactions = false, bool $showlockedactivities = true): string {
    $modinfo = get_fast_modinfo($course);

    if ($showstatus) {
        return format_selfstudy_render_fallback_cm_list($course, $section, $modinfo, true, $completion, $showactions,
            $showlockedactivities);
    }

    if (function_exists('print_section')) {
        ob_start();
        print_section($course, $section->section, $modinfo->get_cms(), $modinfo->get_used_module_names(), true);
        $output = ob_get_clean();

        if (trim($output) !== '') {
            return $output;
        }
    }

    return format_selfstudy_render_fallback_cm_list($course, $section, $modinfo, false, null, $showactions,
        $showlockedactivities);
}

/**
 * Renders a simple activity list if Moodle's legacy section renderer is unavailable.
 *
 * @param stdClass $course
 * @param section_info $section
 * @param course_modinfo $modinfo
 * @param bool $showstatus
 * @param completion_info|null $completion
 * @param bool $showactions
 * @return string
 */
function format_selfstudy_render_fallback_cm_list(stdClass $course, section_info $section, course_modinfo $modinfo,
        bool $showstatus = false, ?completion_info $completion = null, bool $showactions = false,
        bool $showlockedactivities = true): string {
    if (empty($modinfo->sections[$section->section])) {
        return '';
    }

    $items = [];
    foreach ($modinfo->sections[$section->section] as $cmid) {
        $cm = $modinfo->get_cm($cmid);
        if (!$cm->uservisible || (empty($cm->url) && $cm->modname !== 'learningmap')) {
            continue;
        }
        if (!$showlockedactivities && $showstatus && $completion &&
                format_selfstudy_get_cm_status_key($cm, $completion) === 'locked') {
            continue;
        }

        $icon = html_writer::empty_tag('img', [
            'src' => $cm->get_icon_url()->out(false),
            'class' => 'iconlarge activityicon',
            'alt' => '',
            'role' => 'presentation',
        ]);
        $viewurl = $cm->url ?: new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]);
        $link = html_writer::link($viewurl, format_string($cm->name), ['class' => 'aalink']);
        $learninggoal = trim(format_selfstudy_get_cm_learninggoal((int)$cm->id));
        $activitymain = html_writer::span($link, 'activityname');
        if ($learninggoal !== '') {
            $activitymain .= html_writer::div(format_text($learninggoal, FORMAT_PLAIN),
                'format-selfstudy-activitygoal');
        }
        $competencies = format_selfstudy_get_cm_core_competency_labels((int)$course->id, (int)$cm->id);
        if ($competencies) {
            $activitymain .= html_writer::div(
                s(get_string('activitycompetencies', 'format_selfstudy') . ': ' . implode(', ', $competencies)),
                'format-selfstudy-activitycompetencies'
            );
        }
        $status = '';
        if ($showstatus && $completion && format_selfstudy_is_learning_activity($cm)) {
            $statuskey = format_selfstudy_get_cm_status_key($cm, $completion);
            $statuslabel = format_selfstudy_get_cm_status_label($cm, $completion);
            $status = html_writer::span(
                $statuslabel,
                'format-selfstudy-activitystatus format-selfstudy-activitystatus-' . $statuskey,
                ['aria-label' => get_string('learningpathaccessiblestatus', 'format_selfstudy', $statuslabel)]
            );
        }
        $content = html_writer::span($icon, 'activityiconcontainer') .
            html_writer::span($activitymain, 'format-selfstudy-activitymain') .
            $status;
        if ($showactions) {
            $content .= format_selfstudy_render_cm_action_menu($cm);
        }

        $items[] = html_writer::tag('li',
            html_writer::div($content, 'activity-item'),
            [
                'class' => 'activity ' . $cm->modname . ' modtype_' . $cm->modname,
                'id' => 'module-' . $cm->id,
                'data-for' => 'cmitem',
                'data-id' => $cm->id,
            ]
        );
    }

    if (!$items) {
        return '';
    }

    return html_writer::tag('ul', implode('', $items), [
        'class' => 'section img-text',
        'data-for' => 'cmlist',
        'data-id' => $section->id,
    ]);
}

/**
 * Renders Learningmap modules that Moodle's core component renderer skips because cm_info has no URL.
 *
 * @param stdClass $course
 * @param int|null $displaysection
 * @return string
 */
function format_selfstudy_render_learningmap_editing_fallback(stdClass $course, ?int $displaysection = null): string {
    $modinfo = get_fast_modinfo($course);
    $sections = $modinfo->get_section_info_all();
    $items = [];

    foreach ($modinfo->get_cms() as $cm) {
        if ($cm->modname !== 'learningmap' || !$cm->uservisible || !empty($cm->url)) {
            continue;
        }
        if ($displaysection !== null && (int)$cm->sectionnum !== (int)$displaysection) {
            continue;
        }

        $section = $sections[(int)$cm->sectionnum] ?? null;
        $sectionname = $section ? get_section_name($course, $section) :
            get_string('section') . ' ' . (int)$cm->sectionnum;
        $url = new moodle_url('/mod/learningmap/view.php', ['id' => (int)$cm->id]);
        $editurl = new moodle_url('/course/modedit.php', ['update' => (int)$cm->id, 'return' => 1]);

        $items[] = html_writer::tag('li',
            html_writer::link($url, format_string($cm->name), ['class' => 'aalink']) .
            html_writer::span(' ' . s($sectionname), 'text-muted small') .
            html_writer::link($editurl, get_string('settings'), ['class' => 'btn btn-secondary btn-sm ml-2']),
            ['class' => 'activity learningmap modtype_learningmap']
        );
    }

    if (!$items) {
        return '';
    }

    return html_writer::div(
        html_writer::tag('h3', get_string('learningmapsectionmaps', 'format_selfstudy')) .
        html_writer::tag('ul', implode('', $items), ['class' => 'section img-text']),
        'format-selfstudy-learningmap-editing-fallback'
    );
}

/**
 * Renders the learner's active learning path choice and progress.
 *
 * @param stdClass $course
 * @param stdClass[] $paths
 * @param stdClass|null $activepath
 * @param stdClass|null $progress
 * @param stdClass[] $outline
 * @param string $pathpointcolor
 * @return string
 */
function format_selfstudy_render_path_choice(stdClass $course, array $paths, ?stdClass $activepath,
        ?stdClass $progress, array $outline = [], string $pathpointcolor = '#6f1ab1'): string {
    $renderer = new \format_selfstudy\local\base_renderer();
    return $renderer->render_path_choice($course, $paths, $activepath, $progress, $outline, $pathpointcolor);
}

/**
 * Renders a learner-facing builder for a personal path from course sections.
 *
 * @param stdClass $course
 * @param stdClass[] $summaries
 * @param stdClass|null $activepath
 * @return string
 */
function format_selfstudy_render_personal_path_builder(stdClass $course, array $summaries, ?stdClass $activepath): string {
    $repository = new \format_selfstudy\local\path_repository();
    $templates = $repository->get_paths((int)$course->id, true, 0);
    if ($templates) {
        $template = $repository->get_path_with_items((int)$templates[0]->id);
        if ($template) {
            $output = format_selfstudy_render_personal_path_template_builder($course, $template, $activepath);
            if ($output !== '') {
                return $output;
            }
        }
    }

    $sections = array_values(array_filter($summaries, static function(stdClass $summary): bool {
        return !empty($summary->cms);
    }));
    if (!$sections) {
        return '';
    }

    $selectedsectionids = format_selfstudy_get_personal_path_sectionids($activepath);
    $output = html_writer::start_tag('section', ['class' => 'format-selfstudy-personalpath']);
    $output .= html_writer::tag('h3', get_string('learningpathpersonal', 'format_selfstudy'));
    $output .= html_writer::tag('p', get_string('learningpathpersonalintro', 'format_selfstudy'), ['class' => 'text-muted']);
    $output .= html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url('/course/format/selfstudy/path_select.php'))->out(false),
        'class' => 'format-selfstudy-personalpath-form',
    ]);
    $output .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $course->id]);
    $output .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'personal']);
    $output .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    $output .= html_writer::start_div('format-selfstudy-personalpath-options');
    foreach ($sections as $summary) {
        $section = $summary->section;
        $sectionid = (int)$section->id;
        $isrequired = $summary->pathkind !== 'optional';
        $checked = $isrequired || in_array($sectionid, $selectedsectionids, true);
        $inputid = 'format-selfstudy-personal-section-' . $sectionid;
        $meta = get_string($isrequired ? 'required' : 'optional', 'format_selfstudy') . ' - ' . $summary->statuslabel;

        if ($isrequired) {
            $output .= html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'sectionids[]',
                'value' => $sectionid,
            ]);
        }

        $output .= html_writer::start_div('format-selfstudy-personalpath-option');
        $output .= html_writer::empty_tag('input', [
            'type' => 'checkbox',
            'name' => 'sectionids[]',
            'value' => $sectionid,
            'id' => $inputid,
            'checked' => $checked ? 'checked' : null,
            'disabled' => $isrequired ? 'disabled' : null,
        ]);
        $output .= html_writer::start_div('format-selfstudy-personalpath-optionbody');
        $output .= html_writer::label(format_selfstudy_get_section_title($course, $section), $inputid, false,
            ['class' => 'format-selfstudy-personalpath-title']);
        $output .= html_writer::div($meta, 'format-selfstudy-personalpath-meta');
        if ($summary->learninggoal !== '') {
            $output .= html_writer::div(format_text($summary->learninggoal, FORMAT_PLAIN),
                'format-selfstudy-personalpath-goal');
        }
        $output .= html_writer::end_div();
        $output .= html_writer::end_div();
    }
    $output .= html_writer::end_div();

    $output .= html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-primary',
        'value' => get_string('learningpathpersonalbutton', 'format_selfstudy'),
    ]);
    $output .= html_writer::end_tag('form');
    $output .= html_writer::end_tag('section');

    return $output;
}

/**
 * Returns whether learners have a real path choice to make.
 *
 * @param stdClass $course
 * @param stdClass[] $learningpaths
 * @param stdClass[] $summaries
 * @param \format_selfstudy\local\path_repository $repository
 * @return bool
 */
function format_selfstudy_has_personal_path_choices(stdClass $course, array $learningpaths, array $summaries,
        \format_selfstudy\local\path_repository $repository): bool {
    foreach ($learningpaths as $path) {
        $template = $repository->get_path_with_items((int)$path->id);
        if (!$template) {
            continue;
        }

        $blocks = format_selfstudy_get_milestone_blocks_from_path_items($template->items ?? [], $course);
        if (!$blocks) {
            continue;
        }

        foreach (format_selfstudy_group_milestone_blocks($blocks) as $group) {
            if (count($group) > 1) {
                return true;
            }
        }

        return false;
    }

    foreach ($summaries as $summary) {
        if (!empty($summary->cms) && ($summary->pathkind ?? 'required') === 'optional') {
            return true;
        }
    }

    return false;
}

/**
 * Renders a learner-facing builder based on a published milestone path.
 *
 * @param stdClass $course
 * @param stdClass $template
 * @param stdClass|null $activepath
 * @return string
 */
function format_selfstudy_render_personal_path_template_builder(stdClass $course, stdClass $template,
        ?stdClass $activepath): string {
    $blocks = format_selfstudy_get_milestone_blocks_from_path_items($template->items ?? [], $course);
    if (!$blocks) {
        return '';
    }

    $selectedkeys = format_selfstudy_get_personal_path_milestone_keys($activepath);
    $groups = format_selfstudy_group_milestone_blocks($blocks);
    $output = html_writer::start_tag('section', ['class' => 'format-selfstudy-personalpath']);
    $output .= html_writer::tag('h3', get_string('learningpathpersonal', 'format_selfstudy'));
    $output .= html_writer::tag('p', get_string('learningpathpersonalintro', 'format_selfstudy'), ['class' => 'text-muted']);
    $output .= html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url('/course/format/selfstudy/path_select.php'))->out(false),
        'class' => 'format-selfstudy-personalpath-form',
    ]);
    $output .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $course->id]);
    $output .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'personal']);
    $output .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'templatepathid', 'value' => (int)$template->id]);
    $output .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    $output .= html_writer::start_div('format-selfstudy-personalpath-options');
    foreach ($groups as $groupindex => $group) {
        $isalternativegroup = count($group) > 1;
        $output .= html_writer::start_div('format-selfstudy-personalpath-optiongroup');
        if ($isalternativegroup) {
            $output .= html_writer::div(get_string('learningpathpersonalchooseone', 'format_selfstudy'),
                'format-selfstudy-personalpath-meta');
        }
        foreach ($group as $block) {
            $key = $block->key;
            $checked = !$isalternativegroup || in_array($key, $selectedkeys, true) ||
                ($isalternativegroup && !$selectedkeys && $block === $group[0]);
            $inputid = 'format-selfstudy-personal-milestone-' . $groupindex . '-' . clean_param($key, PARAM_ALPHANUMEXT);
            if (!$isalternativegroup) {
                $output .= html_writer::empty_tag('input', [
                    'type' => 'hidden',
                    'name' => 'milestonekeys[]',
                    'value' => $key,
                ]);
            }
            $output .= html_writer::start_div('format-selfstudy-personalpath-option');
            $output .= html_writer::empty_tag('input', [
                'type' => 'checkbox',
                'name' => 'milestonekeys[]',
                'value' => $key,
                'id' => $inputid,
                'checked' => $checked ? 'checked' : null,
                'disabled' => !$isalternativegroup ? 'disabled' : null,
            ]);
            $output .= html_writer::start_div('format-selfstudy-personalpath-optionbody');
            $output .= html_writer::label($block->title, $inputid, false,
                ['class' => 'format-selfstudy-personalpath-title']);
            $output .= html_writer::div(get_string($isalternativegroup ? 'learningpathmilestonealternative' :
                'required', 'format_selfstudy'), 'format-selfstudy-personalpath-meta');
            $output .= html_writer::end_div();
            $output .= html_writer::end_div();
        }
        $output .= html_writer::end_div();
    }
    $output .= html_writer::end_div();

    $output .= html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-primary',
        'value' => get_string('learningpathpersonalbutton', 'format_selfstudy'),
    ]);
    $output .= html_writer::end_tag('form');
    $output .= html_writer::end_tag('section');

    return $output;
}

/**
 * Returns section ids stored in the active personal path.
 *
 * @param stdClass|null $activepath
 * @return int[]
 */
function format_selfstudy_get_personal_path_sectionids(?stdClass $activepath): array {
    global $USER;

    if (!$activepath || (int)($activepath->userid ?? 0) !== (int)$USER->id) {
        return [];
    }

    $repository = new \format_selfstudy\local\path_repository();
    $sectionids = [];
    foreach ($repository->get_path_items((int)$activepath->id) as $item) {
        if ($item->itemtype === \format_selfstudy\local\path_repository::ITEM_SEQUENCE && !empty($item->sectionid)) {
            $sectionids[] = (int)$item->sectionid;
        }
    }

    return array_values(array_unique($sectionids));
}

/**
 * Returns milestone keys stored in the active personal path.
 *
 * @param stdClass|null $activepath
 * @return string[]
 */
function format_selfstudy_get_personal_path_milestone_keys(?stdClass $activepath): array {
    global $USER;

    if (!$activepath || (int)($activepath->userid ?? 0) !== (int)$USER->id) {
        return [];
    }

    $repository = new \format_selfstudy\local\path_repository();
    $keys = [];
    foreach ($repository->get_path_items((int)$activepath->id) as $item) {
        if ($item->itemtype !== \format_selfstudy\local\path_repository::ITEM_MILESTONE) {
            continue;
        }
        $key = format_selfstudy_get_path_item_config_value($item, 'milestonekey');
        if ($key !== '') {
            $keys[] = $key;
        }
    }

    return array_values(array_unique($keys));
}

/**
 * Returns milestone blocks from a flat path item list.
 *
 * @param stdClass[] $items
 * @param stdClass|null $course
 * @return stdClass[]
 */
function format_selfstudy_get_milestone_blocks_from_path_items(array $items, ?stdClass $course = null): array {
    $children = [];
    foreach ($items as $item) {
        if (!empty($item->parentid)) {
            $children[(int)$item->parentid][] = $item;
        }
    }
    $rootitems = array_values(array_filter($items, static function(stdClass $item): bool {
        return empty($item->parentid);
    }));
    usort($rootitems, static function(stdClass $left, stdClass $right): int {
        return ((int)($left->sortorder ?? 0) <=> (int)($right->sortorder ?? 0)) ?:
            ((int)($left->id ?? 0) <=> (int)($right->id ?? 0));
    });

    $blocks = [];
    $current = null;
    foreach ($rootitems as $item) {
        if ($item->itemtype === \format_selfstudy\local\path_repository::ITEM_MILESTONE) {
            if ($current) {
                $blocks[] = format_selfstudy_prepare_milestone_block_for_personal_path($current, $course, $children);
            }
            $current = (object)[
                'item' => $item,
                'items' => [$item],
            ];
            continue;
        }
        if ($current) {
            $current->items[] = $item;
        }
    }
    if ($current) {
        $blocks[] = format_selfstudy_prepare_milestone_block_for_personal_path($current, $course, $children);
    }

    return array_values(array_filter($blocks));
}

/**
 * Prepares one milestone block for the personal path builder.
 *
 * @param stdClass $block
 * @param stdClass|null $course
 * @param array $children
 * @return stdClass|null
 */
function format_selfstudy_prepare_milestone_block_for_personal_path(stdClass $block, ?stdClass $course,
        array $children): ?stdClass {
    $item = $block->item;
    $key = format_selfstudy_get_path_item_config_value($item, 'milestonekey');
    if ($key === '') {
        return null;
    }
    $title = format_string($item->title, true);
    $sectiontitle = format_selfstudy_get_path_item_section_title($item, $course);
    if ($sectiontitle === '') {
        $sectiontitle = format_selfstudy_get_path_item_section_title_from_block_items($block->items, $course, $children);
    }
    if ($sectiontitle !== '') {
        $title = $sectiontitle;
    }

    return (object)[
        'key' => $key,
        'alternativepeers' => format_selfstudy_get_path_item_config_array($item, 'alternativepeers'),
        'title' => $title,
    ];
}

/**
 * Returns a milestone section title from configdata.
 *
 * @param stdClass $item
 * @param stdClass|null $course
 * @return string
 */
function format_selfstudy_get_path_item_section_title(stdClass $item, ?stdClass $course): string {
    if (!$course) {
        return '';
    }
    $sectionnum = (int)format_selfstudy_get_path_item_config_value($item, 'sectionnum');
    if ($sectionnum <= 0) {
        return '';
    }
    $modinfo = get_fast_modinfo($course);
    $section = $modinfo->get_section_info($sectionnum, IGNORE_MISSING);
    if (!$section) {
        return '';
    }
    $title = trim((string)($section->name ?? ''));
    if ($title === '') {
        $title = trim(get_section_name($course, $section));
    }
    return $title !== '' ? $title : get_string('section') . ' ' . $sectionnum;
}

/**
 * Returns the section title of the first activity in a milestone block.
 *
 * @param stdClass[] $items
 * @param stdClass|null $course
 * @param array $children
 * @return string
 */
function format_selfstudy_get_path_item_section_title_from_block_items(array $items, ?stdClass $course,
        array $children): string {
    if (!$course) {
        return '';
    }
    $modinfo = get_fast_modinfo($course);
    foreach ($items as $item) {
        $cmids = [];
        if ($item->itemtype === \format_selfstudy\local\path_repository::ITEM_STATION && !empty($item->cmid)) {
            $cmids[] = (int)$item->cmid;
        }
        foreach ($children[(int)($item->id ?? 0)] ?? [] as $child) {
            if ($child->itemtype === \format_selfstudy\local\path_repository::ITEM_STATION && !empty($child->cmid)) {
                $cmids[] = (int)$child->cmid;
            }
        }
        foreach ($cmids as $cmid) {
            try {
                $cm = $modinfo->get_cm($cmid);
            } catch (Throwable $exception) {
                continue;
            }
            $section = $modinfo->get_section_info((int)$cm->sectionnum, IGNORE_MISSING);
            if ($section) {
                $title = trim((string)($section->name ?? ''));
                if ($title === '') {
                    $title = trim(get_section_name($course, $section));
                }
                return $title !== '' ? $title : get_string('section') . ' ' . (int)$cm->sectionnum;
            }
        }
    }

    return '';
}

/**
 * Groups milestone blocks by alternative peer relationships.
 *
 * @param stdClass[] $blocks
 * @return array
 */
function format_selfstudy_group_milestone_blocks(array $blocks): array {
    $bykey = [];
    foreach ($blocks as $index => $block) {
        $bykey[$block->key] = $index;
    }

    $visited = [];
    $groups = [];
    foreach ($blocks as $index => $block) {
        if (!empty($visited[$index])) {
            continue;
        }
        $indexes = [];
        format_selfstudy_collect_milestone_block_group($index, $blocks, $bykey, $visited, $indexes);
        sort($indexes);
        $groups[] = array_map(static function(int $groupindex) use ($blocks): stdClass {
            return $blocks[$groupindex];
        }, $indexes);
    }

    return $groups;
}

/**
 * Collects one connected alternative milestone group.
 *
 * @param int $index
 * @param stdClass[] $blocks
 * @param array $bykey
 * @param array $visited
 * @param int[] $indexes
 */
function format_selfstudy_collect_milestone_block_group(int $index, array $blocks, array $bykey, array &$visited,
        array &$indexes): void {
    if (!empty($visited[$index])) {
        return;
    }
    $visited[$index] = true;
    $indexes[] = $index;
    foreach ($blocks[$index]->alternativepeers as $peerkey) {
        if (isset($bykey[$peerkey])) {
            format_selfstudy_collect_milestone_block_group($bykey[$peerkey], $blocks, $bykey, $visited, $indexes);
        }
    }
}

/**
 * Returns one path item config value.
 *
 * @param stdClass $item
 * @param string $name
 * @return string
 */
function format_selfstudy_get_path_item_config_value(stdClass $item, string $name): string {
    $config = json_decode((string)($item->configdata ?? ''), true);
    return is_array($config) ? clean_param((string)($config[$name] ?? ''), PARAM_ALPHANUMEXT) : '';
}

/**
 * Returns one path item config array.
 *
 * @param stdClass $item
 * @param string $name
 * @return string[]
 */
function format_selfstudy_get_path_item_config_array(stdClass $item, string $name): array {
    $config = json_decode((string)($item->configdata ?? ''), true);
    $values = is_array($config) && is_array($config[$name] ?? null) ? $config[$name] : [];
    return array_values(array_filter(array_map(static function($value): string {
        return clean_param((string)$value, PARAM_ALPHANUMEXT);
    }, $values)));
}

/**
 * Converts path outline entries into JSON-friendly data for JavaScript.
 *
 * @param stdClass[] $outline
 * @return array
 */
function format_selfstudy_prepare_path_outline_for_js(array $outline): array {
    $items = [];
    foreach ($outline as $entry) {
        $items[] = [
            'id' => (int)$entry->id,
            'type' => (string)$entry->type,
            'level' => (int)$entry->level,
            'title' => (string)$entry->title,
            'status' => (string)$entry->status,
            'statusLabel' => format_selfstudy_get_path_status_label($entry),
            'ariaLabel' => format_selfstudy_get_path_item_aria_label($entry,
                format_selfstudy_get_path_status_label($entry)),
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
            'competencies' => array_values((array)($entry->competencies ?? [])),
        ];
    }

    return $items;
}

/**
 * Filters locked path outline entries for display only.
 *
 * @param stdClass[] $outline
 * @param bool $showlockedactivities
 * @return stdClass[]
 */
function format_selfstudy_filter_locked_outline(array $outline, bool $showlockedactivities): array {
    if ($showlockedactivities) {
        return $outline;
    }

    return array_values(array_filter($outline, static function(stdClass $entry): bool {
        return $entry->status !== 'locked';
    }));
}

/**
 * Renders the learner's recent and upcoming path movement.
 *
 * @param stdClass[] $outline
 * @return string
 */
function format_selfstudy_render_learning_trace(array $outline): string {
    $renderer = new \format_selfstudy\local\base_renderer();
    return $renderer->render_learning_trace($outline);
}

/**
 * Renders one trace list.
 *
 * @param string $title
 * @param stdClass[] $items
 * @param string $type
 * @return string
 */
function format_selfstudy_render_learning_trace_list(string $title, array $items, string $type): string {
    $renderer = new \format_selfstudy\local\base_renderer();
    return $renderer->render_learning_trace_list($title, $items, $type);
}

/**
 * Renders the active path as an explicit accessible alternative to the map.
 *
 * @param stdClass $activepath
 * @param stdClass|null $progress
 * @param stdClass[] $outline
 * @param cm_info|null $mainmapcm
 * @param string $pathpointcolor
 * @return string
 */
function format_selfstudy_render_accessible_path_view(stdClass $activepath, ?stdClass $progress, array $outline,
        ?cm_info $mainmapcm, string $pathpointcolor = '#6f1ab1', bool $showlockedactivities = true): string {
    $renderer = new \format_selfstudy\local\base_renderer();
    return $renderer->render_accessible_path_view($activepath, $progress, $outline, $mainmapcm, $pathpointcolor,
        $showlockedactivities);
}

/**
 * Finds previous and next stations for the accessible path navigation.
 *
 * @param stdClass[] $outline
 * @return array
 */
function format_selfstudy_get_path_outline_navigation(array $outline): array {
    $renderer = new \format_selfstudy\local\base_renderer();
    return $renderer->get_path_outline_navigation($outline);
}

/**
 * Renders a compact learning path outline.
 *
 * @param stdClass[] $outline
 * @param string $class
 * @param string $pathpointcolor
 * @return string
 */
function format_selfstudy_render_path_outline(array $outline, string $class = 'format-selfstudy-pathoutline',
        string $pathpointcolor = '#6f1ab1'): string {
    $renderer = new \format_selfstudy\local\base_renderer();
    return $renderer->render_path_outline($outline, $class, $pathpointcolor);
}

/**
 * Renders a single learning path outline entry.
 *
 * @param stdClass $entry
 * @param bool $uselevelmargin
 * @return string
 */
function format_selfstudy_render_path_outline_entry(stdClass $entry, bool $uselevelmargin = true): string {
    $renderer = new \format_selfstudy\local\base_renderer();
    return $renderer->render_path_outline_entry($entry, $uselevelmargin);
}

/**
 * Returns a localized path status label.
 *
 * @param stdClass $entry
 * @return string
 */
function format_selfstudy_get_path_status_label(stdClass $entry): string {
    $renderer = new \format_selfstudy\local\base_renderer();
    return $renderer->get_path_status_label($entry);
}

/**
 * Returns a compact screen reader label for one path item.
 *
 * @param stdClass $entry
 * @param string $statuslabel
 * @return string
 */
function format_selfstudy_get_path_item_aria_label(stdClass $entry, string $statuslabel): string {
    $renderer = new \format_selfstudy\local\base_renderer();
    return $renderer->get_path_item_aria_label($entry, $statuslabel);
}

/**
 * Renders a Moodle action menu for section management.
 *
 * @param section_info $section
 * @param string $sectiontitle
 * @return string
 */
function format_selfstudy_render_section_action_menu(section_info $section, string $sectiontitle): string {
    global $OUTPUT;

    $editurl = new moodle_url('/course/editsection.php', ['id' => $section->id]);

    $menu = new action_menu();
    $menu->set_owner_selector('format-selfstudy-section-actions-' . $section->id);
    $menu->set_kebab_trigger(get_string('actions'), $OUTPUT);
    $menu->set_additional_classes('format-selfstudy-section-actionmenu');

    $menu->add(new action_menu_link_secondary(
        $editurl,
        new pix_icon('t/edit', ''),
        get_string('editsection', 'format_selfstudy') . ': ' . $sectiontitle
    ));

    return html_writer::span($OUTPUT->render($menu), 'format-selfstudy-sectioncontrols');
}

/**
 * Renders a Moodle action menu for basic activity management.
 *
 * @param cm_info $cm
 * @return string
 */
function format_selfstudy_render_cm_action_menu(cm_info $cm): string {
    global $COURSE, $OUTPUT;

    $viewurl = $cm->url ?: new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]);
    $editurl = new moodle_url('/course/modedit.php', [
        'update' => $cm->id,
        'return' => 1,
    ]);
    $duplicateurl = new moodle_url('/course/mod.php', [
        'duplicate' => $cm->id,
        'sesskey' => sesskey(),
    ]);
    $visibilityurl = new moodle_url('/course/mod.php', [
        $cm->visible ? 'hide' : 'show' => $cm->id,
        'sesskey' => sesskey(),
    ]);
    $moveurl = new moodle_url('/course/mod.php', [
        'copy' => $cm->id,
        'sesskey' => sesskey(),
    ]);
    $permalinkurl = new moodle_url('/course/view.php', [
        'id' => $cm->course,
    ], 'module-' . $cm->id);
    $groupmodeurl = new moodle_url('/course/mod.php', [
        'id' => $cm->id,
        'sesskey' => sesskey(),
        'groupmode' => format_selfstudy_get_next_groupmode($cm),
    ]);
    $deleteurl = new moodle_url('/course/mod.php', [
        'delete' => $cm->id,
        'sesskey' => sesskey(),
    ]);

    $menu = new action_menu();
    $menu->set_owner_selector('format-selfstudy-cm-actions-' . $cm->id);
    $menu->set_kebab_trigger(get_string('actions'), $OUTPUT);
    $menu->set_additional_classes('format-selfstudy-cm-actionmenu');

    $menu->add(new action_menu_link_secondary(
        $viewurl,
        new pix_icon('i/search', ''),
        get_string('view')
    ));
    $menu->add(new action_menu_link_secondary(
        $editurl,
        new pix_icon('t/edit', ''),
        get_string('editsettings')
    ));
    $menu->add(new action_menu_link_secondary(
        $duplicateurl,
        new pix_icon('t/copy', ''),
        get_string('duplicate')
    ));
    $menu->add(new action_menu_link_secondary(
        $visibilityurl,
        new pix_icon($cm->visible ? 't/hide' : 't/show', ''),
        get_string($cm->visible ? 'hide' : 'show')
    ));
    $menu->add(new action_menu_link_secondary(
        $moveurl,
        new pix_icon('t/move', ''),
        get_string('move')
    ));
    $menu->add(new action_menu_link_secondary(
        $permalinkurl,
        new pix_icon('i/link', ''),
        get_string('activitypermalink', 'format_selfstudy')
    ));
    if (!empty($COURSE->groupmode) || !empty($COURSE->groupmodeforce) || $cm->groupmode !== NOGROUPS) {
        $menu->add(new action_menu_link_secondary(
            $groupmodeurl,
            new pix_icon('i/group', ''),
            get_string('groupmode', 'format_selfstudy')
        ));
    }
    $menu->add(new action_menu_link_secondary(
        $deleteurl,
        new pix_icon('t/delete', ''),
        get_string('delete'),
        ['class' => 'format-selfstudy-menu-delete']
    ));

    return html_writer::span($OUTPUT->render($menu), 'format-selfstudy-cmcontrols');
}

/**
 * Gets the next group mode for a simple group mode toggle.
 *
 * @param cm_info $cm
 * @return int
 */
function format_selfstudy_get_next_groupmode(cm_info $cm): int {
    if ((int)$cm->groupmode === NOGROUPS) {
        return SEPARATEGROUPS;
    }
    if ((int)$cm->groupmode === SEPARATEGROUPS) {
        return VISIBLEGROUPS;
    }
    return NOGROUPS;
}

/**
 * Builds section summaries for the learning overview.
 *
 * @param stdClass $course
 * @param core_courseformat\base $format
 * @param course_modinfo $modinfo
 * @param section_info[] $sections
 * @param completion_info $completion
 * @param stdClass|null $learningmapconfig
 * @return stdClass[]
 */
function format_selfstudy_get_learning_summaries(stdClass $course, core_courseformat\base $format, course_modinfo $modinfo,
        array $sections, completion_info $completion, ?stdClass $learningmapconfig = null): array {
    global $USER;

    $summaries = [];

    foreach ($sections as $section) {
        if (!$section->uservisible) {
            continue;
        }

        $sectionoptions = $format->get_format_options($section);
        $summary = new stdClass();
        $summary->section = $section;
        $summary->pathkind = ($sectionoptions['pathkind'] ?? 'required') === 'optional' ? 'optional' : 'required';
        $summary->learninggoal = trim($sectionoptions['learninggoal'] ?? '');
        $summary->sectionmapcm = format_selfstudy_get_learningmap_section_cm($course, $modinfo, (int)$section->id,
            $learningmapconfig);
        $summary->cms = [];
        $summary->completionenabled = 0;
        $summary->completioncomplete = 0;
        $summary->nextcm = null;

        if (!empty($modinfo->sections[$section->section])) {
            foreach ($modinfo->sections[$section->section] as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if (!$cm->uservisible) {
                    continue;
                }

                $summary->cms[] = $cm;

                if (!format_selfstudy_is_learning_activity($cm)) {
                    continue;
                }

                if (!$completion->is_enabled($cm)) {
                    if ($summary->nextcm === null) {
                        $summary->nextcm = $cm;
                    }
                    continue;
                }

                $summary->completionenabled++;
                $data = $completion->get_data($cm, false, $USER->id);
                if (format_selfstudy_is_completion_done((int)$data->completionstate)) {
                    $summary->completioncomplete++;
                    continue;
                }

                if ($summary->nextcm === null) {
                    $summary->nextcm = $cm;
                }
            }
        }

        if ($summary->completionenabled > 0 && $summary->completionenabled === $summary->completioncomplete) {
            $summary->statuskey = 'complete';
            $summary->statuslabel = get_string('statuscomplete', 'format_selfstudy');
        } else if ($summary->completioncomplete > 0) {
            $summary->statuskey = 'inprogress';
            $summary->statuslabel = get_string('statusinprogress', 'format_selfstudy');
        } else if ($summary->cms) {
            $summary->statuskey = 'notstarted';
            $summary->statuslabel = get_string('statusnotstarted', 'format_selfstudy');
        } else {
            $summary->statuskey = 'available';
            $summary->statuslabel = get_string('statusavailable', 'format_selfstudy');
        }

        if ($summary->nextcm === null) {
            foreach ($summary->cms as $cm) {
                if (format_selfstudy_is_learning_activity($cm)) {
                    $summary->nextcm = $cm;
                    break;
                }
            }
        }

        $summaries[] = $summary;
    }

    return $summaries;
}

/**
 * Gets a visible Learningmap course module.
 *
 * @param course_modinfo $modinfo
 * @param int $cmid
 * @return cm_info|null
 */
function format_selfstudy_get_learningmap_cm(course_modinfo $modinfo, int $cmid): ?cm_info {
    if (!$cmid) {
        return null;
    }

    try {
        $cm = $modinfo->get_cm($cmid);
    } catch (Throwable $exception) {
        return null;
    }

    if (!$cm->uservisible || $cm->modname !== 'learningmap') {
        return null;
    }

    return $cm;
}

/**
 * Finds the next activity. Required incomplete sections win, optional sections follow.
 *
 * @param stdClass[] $summaries
 * @return cm_info|null
 */
function format_selfstudy_find_next_cm(array $summaries): ?cm_info {
    foreach (['required', 'optional'] as $pathkind) {
        foreach ($summaries as $summary) {
            if ($summary->pathkind !== $pathkind || $summary->statuskey === 'complete') {
                continue;
            }

            if ($summary->nextcm) {
                return $summary->nextcm;
            }
        }
    }

    foreach ($summaries as $summary) {
        if ($summary->nextcm) {
            return $summary->nextcm;
        }
    }

    return null;
}

/**
 * Returns the primary continue URL for the course dashboard.
 *
 * @param stdClass $course
 * @param cm_info|null $mainmapcm
 * @param stdClass|null $activepathprogress
 * @param cm_info|null $fallbackcm
 * @return moodle_url|null
 */
function format_selfstudy_get_continue_url(stdClass $course, ?cm_info $mainmapcm, ?stdClass $activepathprogress,
        ?cm_info $fallbackcm): ?moodle_url {
    if (!empty($activepathprogress->nexturl)) {
        return new moodle_url($activepathprogress->nexturl);
    }

    if (!empty($activepathprogress->total) && $activepathprogress->complete >= $activepathprogress->total) {
        return new moodle_url('/course/view.php', ['id' => $course->id]);
    }

    if ($mainmapcm) {
        return new moodle_url('/mod/learningmap/view.php', ['id' => $mainmapcm->id]);
    }

    if ($fallbackcm) {
        return new moodle_url('/mod/' . $fallbackcm->modname . '/view.php', ['id' => $fallbackcm->id]);
    }

    return null;
}

/**
 * Counts complete or open sections by path kind.
 *
 * @param stdClass[] $summaries
 * @param string $pathkind
 * @param bool $complete
 * @return int
 */
function format_selfstudy_count_sections(array $summaries, string $pathkind, bool $complete): int {
    $count = 0;
    foreach ($summaries as $summary) {
        if ($summary->pathkind !== $pathkind) {
            continue;
        }
        if (($summary->statuskey === 'complete') === $complete) {
            $count++;
        }
    }
    return $count;
}

/**
 * Returns a section status label based on visible activity completion.
 *
 * @param section_info $section
 * @param course_modinfo $modinfo
 * @param completion_info $completion
 * @return string
 */
function format_selfstudy_get_section_status_label(section_info $section, course_modinfo $modinfo,
        completion_info $completion): string {
    global $USER;

    if (empty($modinfo->sections[$section->section])) {
        return get_string('statusavailable', 'format_selfstudy');
    }

    $enabled = 0;
    $complete = 0;

    foreach ($modinfo->sections[$section->section] as $cmid) {
        $cm = $modinfo->get_cm($cmid);
        if (!$cm->uservisible || !$completion->is_enabled($cm)) {
            continue;
        }

        $enabled++;
        $data = $completion->get_data($cm, false, $USER->id);
        if ((int)$data->completionstate === COMPLETION_COMPLETE ||
                (int)$data->completionstate === COMPLETION_COMPLETE_PASS) {
            $complete++;
        }
    }

    if ($enabled > 0 && $enabled === $complete) {
        return get_string('statuscomplete', 'format_selfstudy');
    }

    return get_string('statusavailable', 'format_selfstudy');
}

/**
 * Checks whether a completion state is done.
 *
 * @param int $completionstate
 * @return bool
 */
function format_selfstudy_is_completion_done(int $completionstate): bool {
    return $completionstate === COMPLETION_COMPLETE || $completionstate === COMPLETION_COMPLETE_PASS;
}

/**
 * Checks whether a course module should be used as a learning step.
 *
 * @param cm_info $cm
 * @return bool
 */
function format_selfstudy_is_learning_activity(cm_info $cm): bool {
    if (empty($cm->url)) {
        return false;
    }

    $excludedmods = [
        'learningmap',
        'qbank',
    ];

    return !in_array($cm->modname, $excludedmods, true);
}

/**
 * Returns an activity status label.
 *
 * @param cm_info $cm
 * @param completion_info $completion
 * @return string
 */
function format_selfstudy_get_cm_status_label(cm_info $cm, completion_info $completion): string {
    $statuskey = format_selfstudy_get_cm_status_key($cm, $completion);

    if ($statuskey === 'complete') {
        return get_string('statuscomplete', 'format_selfstudy');
    }
    if ($statuskey === 'notstarted') {
        return get_string('statusnotstarted', 'format_selfstudy');
    }

    return get_string('statusavailable', 'format_selfstudy');
}

/**
 * Returns an activity status key based on completion.
 *
 * @param cm_info $cm
 * @param completion_info $completion
 * @return string
 */
function format_selfstudy_get_cm_status_key(cm_info $cm, completion_info $completion): string {
    global $USER;

    if (!$completion->is_enabled($cm)) {
        return 'available';
    }

    $data = $completion->get_data($cm, false, $USER->id);
    if (format_selfstudy_is_completion_done((int)$data->completionstate)) {
        return 'complete';
    }

    return 'notstarted';
}
