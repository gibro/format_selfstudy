<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/completionlib.php');

$format = course_get_format($course);
$formatoptions = $format->get_format_options();
$modinfo = get_fast_modinfo($course);
$sections = $modinfo->get_section_info_all();
$completion = new completion_info($course);
$learningsummaries = format_selfstudy_get_learning_summaries($course, $format, $modinfo, $sections, $completion);
$coursecontext = context_course::instance($course->id);
$caneditsections = $PAGE->user_is_editing() && has_capability('moodle/course:update', $coursecontext);
$courserenderer = $PAGE->get_renderer('core', 'course');

$mainmapcm = null;
if (!empty($formatoptions['mainlearningmap'])) {
    try {
        $mainmapcm = $modinfo->get_cm((int)$formatoptions['mainlearningmap']);
    } catch (Throwable $exception) {
        $mainmapcm = null;
    }
    if ($mainmapcm && !$mainmapcm->uservisible) {
        $mainmapcm = null;
    }
}

$nextcm = format_selfstudy_find_next_cm($learningsummaries);
$requiredopen = format_selfstudy_count_sections($learningsummaries, 'required', false);
$requiredcomplete = format_selfstudy_count_sections($learningsummaries, 'required', true);
$optionalopen = format_selfstudy_count_sections($learningsummaries, 'optional', false);
$optionalcomplete = format_selfstudy_count_sections($learningsummaries, 'optional', true);
$courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);

echo html_writer::start_div('format-selfstudy-dashboard');
echo html_writer::tag('h2', get_string('dashboard', 'format_selfstudy'));

echo html_writer::start_div('format-selfstudy-progress');
echo html_writer::div(
    get_string('required', 'format_selfstudy') . ': ' .
        get_string('statuscomplete', 'format_selfstudy') . ' ' . $requiredcomplete . ', ' .
        get_string('statusavailable', 'format_selfstudy') . ' ' . $requiredopen,
    'format-selfstudy-progressitem format-selfstudy-progress-required'
);
echo html_writer::div(
    get_string('optional', 'format_selfstudy') . ': ' .
        get_string('statuscomplete', 'format_selfstudy') . ' ' . $optionalcomplete . ', ' .
        get_string('statusavailable', 'format_selfstudy') . ' ' . $optionalopen,
    'format-selfstudy-progressitem format-selfstudy-progress-optional'
);
echo html_writer::end_div();

echo html_writer::start_div('format-selfstudy-actions');
if ($nextcm) {
    echo html_writer::link(
        new moodle_url('/mod/' . $nextcm->modname . '/view.php', ['id' => $nextcm->id]),
        get_string('startlearning', 'format_selfstudy'),
        ['class' => 'btn btn-primary']
    );
}
if ($mainmapcm) {
    echo html_writer::link(
        new moodle_url('/mod/learningmap/view.php', ['id' => $mainmapcm->id]),
        get_string('learningmap', 'format_selfstudy'),
        ['class' => 'btn btn-secondary']
    );
}
echo html_writer::end_div();

echo html_writer::start_div('format-selfstudy-mapnotice');
if ($mainmapcm) {
    echo html_writer::tag('strong', get_string('mainlearningmap', 'format_selfstudy') . ': ');
    echo html_writer::link(
        new moodle_url('/mod/learningmap/view.php', ['id' => $mainmapcm->id]),
        format_string($mainmapcm->name)
    );
} else {
    echo html_writer::span(get_string('nolearningmap', 'format_selfstudy'));
}
echo html_writer::end_div();
echo html_writer::end_div();

if (!empty($formatoptions['enablelistview'])) {
    echo html_writer::start_div('format-selfstudy-sections');

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
            !empty($formatoptions['showactivitystatus']),
            $completion,
            $caneditsections
        );
        echo html_writer::end_div();

        if ($caneditsections) {
            echo $courserenderer->course_section_add_cm_control($course, $section->section, $section->section);
        }

        echo html_writer::end_tag('section');
    }

    echo html_writer::end_div();
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
        ?completion_info $completion = null, bool $showactions = false): string {
    $modinfo = get_fast_modinfo($course);

    if ($showstatus) {
        return format_selfstudy_render_fallback_cm_list($course, $section, $modinfo, true, $completion, $showactions);
    }

    if (function_exists('print_section')) {
        ob_start();
        print_section($course, $section->section, $modinfo->get_cms(), $modinfo->get_used_module_names(), true);
        $output = ob_get_clean();

        if (trim($output) !== '') {
            return $output;
        }
    }

    return format_selfstudy_render_fallback_cm_list($course, $section, $modinfo, false, null, $showactions);
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
        bool $showstatus = false, ?completion_info $completion = null, bool $showactions = false): string {
    if (empty($modinfo->sections[$section->section])) {
        return '';
    }

    $items = [];
    foreach ($modinfo->sections[$section->section] as $cmid) {
        $cm = $modinfo->get_cm($cmid);
        if (!$cm->uservisible || empty($cm->url)) {
            continue;
        }

        $icon = html_writer::empty_tag('img', [
            'src' => $cm->get_icon_url()->out(false),
            'class' => 'iconlarge activityicon',
            'alt' => '',
            'role' => 'presentation',
        ]);
        $link = html_writer::link($cm->url, format_string($cm->name), ['class' => 'aalink']);
        $learninggoal = trim(format_selfstudy_get_cm_learninggoal((int)$cm->id));
        $activitymain = html_writer::span($link, 'activityname');
        if ($learninggoal !== '') {
            $activitymain .= html_writer::div(format_text($learninggoal, FORMAT_PLAIN),
                'format-selfstudy-activitygoal');
        }
        $status = '';
        if ($showstatus && $completion && format_selfstudy_is_learning_activity($cm)) {
            $statuskey = format_selfstudy_get_cm_status_key($cm, $completion);
            $status = html_writer::span(
                format_selfstudy_get_cm_status_label($cm, $completion),
                'format-selfstudy-activitystatus format-selfstudy-activitystatus-' . $statuskey
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
        new pix_icon('i/preview', ''),
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
 * @return stdClass[]
 */
function format_selfstudy_get_learning_summaries(stdClass $course, core_courseformat\base $format, course_modinfo $modinfo,
        array $sections, completion_info $completion): array {
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
        $summary->sectionmapcm = format_selfstudy_get_learningmap_cm($modinfo, (int)($sectionoptions['sectionmap'] ?? 0));
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
