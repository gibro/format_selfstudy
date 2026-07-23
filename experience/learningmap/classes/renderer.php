<?php
// This file is part of Moodle - http://moodle.org/

namespace selfstudyexperience_learningmap;

use format_selfstudy\local\experience_renderer_interface;

defined('MOODLE_INTERNAL') || die();

if (!class_exists(__NAMESPACE__ . '\\map_builder')) {
    require_once(__DIR__ . '/map_builder.php');
}
if (!class_exists(__NAMESPACE__ . '\\learningmap_adapter')) {
    require_once(__DIR__ . '/learningmap_adapter.php');
}

/**
 * Learner-facing renderer for the Learningmap selfstudy experience.
 */
class renderer implements experience_renderer_interface {

    /**
     * Returns whether this experience can render for the current course context.
     *
     * @param \stdClass $course
     * @param \stdClass $baseview
     * @param \stdClass $config
     * @return bool
     */
    public function supports(\stdClass $course, \stdClass $baseview, \stdClass $config): bool {
        return true;
    }

    /**
     * Renders optional learner-facing course entry HTML.
     *
     * @param \stdClass $course
     * @param \stdClass $baseview
     * @param \stdClass $config
     * @return string
     */
    public function render_course_entry(\stdClass $course, \stdClass $baseview, \stdClass $config): string {
        $adapter = new learningmap_adapter();
        $cm = $adapter->get_configured_cm($course, $config);
        if (!$cm) {
            return \html_writer::div(get_string('learningmapexperience_setupmissing', 'format_selfstudy'),
                'alert alert-warning');
        }

        $mapmodel = (new map_builder())->build($course, $baseview, $config);
        $mapurl = $adapter->get_map_url($cm, $mapmodel);
        $context = $adapter->describe_context($cm, $mapmodel);
        $backgroundurl = $adapter->get_background_image_url($cm);
        $modeljson = json_encode($mapmodel, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return \html_writer::tag('section',
            $this->render_startpage_hero($course, $baseview, $mapurl, $backgroundurl, $mapmodel, $context) .
            $this->render_section_preview($course),
            [
                'class' => 'format-selfstudy-learningmap-experience format-selfstudy-learningmap-startpage ' .
                    'format-selfstudy-learningmap-theme-' .
                    clean_param((string)($mapmodel->theme ?? 'adventure'), PARAM_ALPHANUMEXT),
                'data-selfstudy-learningmap-model' => $modeljson !== false ? $modeljson : '{}',
            ]
        );
    }

    /**
     * Returns optional activity navigation hints.
     *
     * @param \stdClass $course
     * @param \stdClass $baseview
     * @param \cm_info $cm
     * @param \stdClass $config
     * @return \stdClass|null
     */
    public function get_activity_navigation(\stdClass $course, \stdClass $baseview, \cm_info $cm,
            \stdClass $config): ?\stdClass {
        $adapter = new learningmap_adapter();
        $mapcm = $adapter->get_configured_cm($course, $config);
        if (!$mapcm) {
            return null;
        }

        $mapmodel = (new map_builder())->build($course, $baseview, $config);
        return (object)[
            'mapurl' => $adapter->get_map_url($mapcm, $mapmodel)->out(false),
        ];
    }

    /**
     * Renders a compact model summary for the first version.
     *
     * @param \stdClass $mapmodel
     * @param \stdClass $context
     * @return string
     */
    private function render_status_summary(\stdClass $mapmodel, \stdClass $context): string {
        $counts = [];
        foreach ((array)($mapmodel->nodes ?? []) as $node) {
            $state = (string)($node->gamestate ?? 'available');
            $counts[$state] = ($counts[$state] ?? 0) + 1;
        }

        $labels = [
            'checkpoint' => get_string('learningmapexperience_statecheckpoint', 'format_selfstudy'),
            'discovered' => get_string('learningmapexperience_statediscovered', 'format_selfstudy'),
            'next' => get_string('learningmapexperience_statenext', 'format_selfstudy'),
            'fog' => get_string('learningmapexperience_statefog', 'format_selfstudy'),
            'badge-earned' => get_string('learningmapexperience_statebadgeearned', 'format_selfstudy'),
        ];

        $items = [
            \html_writer::span(get_string('learningmapexperience_stationcount', 'format_selfstudy',
                count((array)($mapmodel->nodes ?? []))), 'format-selfstudy-learningmap-state'),
        ];
        foreach ($labels as $state => $label) {
            if (empty($counts[$state])) {
                continue;
            }
            $items[] = \html_writer::span($label . ': ' . (int)$counts[$state],
                'format-selfstudy-learningmap-state');
        }

        $items[] = \html_writer::span(get_string('learningmapexperience_revision', 'format_selfstudy') . ' ' .
            (int)$context->revision, 'format-selfstudy-learningmap-state');

        return implode(\html_writer::span(' · ', 'format-selfstudy-learningmap-separator'), $items);
    }

    /**
     * Renders the learner-facing course start page hero.
     *
     * @param \stdClass $course
     * @param \stdClass $baseview
     * @param \moodle_url $mapurl
     * @param \moodle_url|null $backgroundurl
     * @param \stdClass $mapmodel
     * @param \stdClass $context
     * @return string
     */
    private function render_startpage_hero(\stdClass $course, \stdClass $baseview, \moodle_url $mapurl,
            ?\moodle_url $backgroundurl, \stdClass $mapmodel, \stdClass $context): string {
        $progress = $baseview->progress ?? (object)['percentage' => 0, 'complete' => 0, 'total' => 0];
        $percentage = max(0, min(100, (int)($progress->percentage ?? 0)));
        $nexturl = trim((string)($progress->nexturl ?? '')) !== '' ? new \moodle_url((string)$progress->nexturl) : $mapurl;
        $summary = trim(strip_tags(format_text((string)($course->summary ?? ''), (int)($course->summaryformat ?? FORMAT_HTML),
            ['context' => \context_course::instance((int)$course->id)])));

        $mapmedia = $backgroundurl ?
            \html_writer::empty_tag('img', [
                'src' => $backgroundurl->out(false),
                'alt' => '',
                'loading' => 'eager',
            ]) :
            \html_writer::div(get_string('learningmapexperience_name', 'format_selfstudy'),
                'format-selfstudy-learningmap-hero-mapfallback');

        return \html_writer::tag('section',
            \html_writer::div(
                \html_writer::div(
                    \html_writer::span(get_string('learningmapexperience_name', 'format_selfstudy'),
                        'format-selfstudy-learningmap-eyebrow') .
                    \html_writer::tag('h2', format_string($course->fullname)) .
                    ($summary !== '' ? \html_writer::tag('p', s($summary)) : '') .
                    \html_writer::div(
                        \html_writer::link($nexturl, get_string('learningpathcontinue', 'format_selfstudy'), [
                            'class' => 'btn btn-primary format-selfstudy-learningmap-primary',
                        ]) .
                        \html_writer::link($mapurl, get_string('learningmapexperience_openlearningmap', 'format_selfstudy'), [
                            'class' => 'btn btn-secondary format-selfstudy-learningmap-open',
                        ]),
                        'format-selfstudy-learningmap-hero-actions'
                    ),
                    'format-selfstudy-learningmap-hero-copy'
                ) .
                \html_writer::div(
                    \html_writer::div($mapmedia, 'format-selfstudy-learningmap-mapimage') .
                    \html_writer::div($this->render_status_summary($mapmodel, $context),
                        'format-selfstudy-learningmap-summary'),
                    'format-selfstudy-learningmap-hero-media'
                ),
                'format-selfstudy-learningmap-hero-grid'
            ) .
            \html_writer::div(
                \html_writer::div(
                    \html_writer::div(
                        \html_writer::span(get_string('courseheroprogress', 'format_selfstudy')) .
                        \html_writer::tag('strong', $percentage . '%'),
                        'format-selfstudy-learningmap-progress-head'
                    ) .
                    \html_writer::div(
                        \html_writer::div('', 'format-selfstudy-learningmap-progress-fill',
                            ['style' => 'width: ' . $percentage . '%']),
                        'format-selfstudy-learningmap-progressbar'
                    ) .
                    \html_writer::div(get_string('courseherorequiredvalue', 'format_selfstudy',
                        (object)['complete' => (int)($progress->complete ?? 0),
                            'open' => max(0, (int)($progress->total ?? 0) - (int)($progress->complete ?? 0))]),
                        'format-selfstudy-learningmap-progress-note'),
                    'format-selfstudy-learningmap-progresscard'
                ) .
                \html_writer::div(
                    \html_writer::tag('h3', get_string('learningmapexperience_journeytitle', 'format_selfstudy')) .
                    \html_writer::tag('p', get_string('learningmapexperience_journeyintro', 'format_selfstudy')),
                    'format-selfstudy-learningmap-journeycard'
                ),
                'format-selfstudy-learningmap-infogrid'
            ),
            ['class' => 'format-selfstudy-learningmap-hero']
        );
    }

    /**
     * Renders a vertical content preview of all course sections.
     *
     * @param \stdClass $course
     * @return string
     */
    private function render_section_preview(\stdClass $course): string {
        global $USER;

        $modinfo = get_fast_modinfo($course);
        $completion = new \completion_info($course);
        $format = course_get_format($course);
        $items = [];

        foreach ($modinfo->get_section_info_all() as $section) {
            if ((int)$section->section === 0 || !$section->uservisible) {
                continue;
            }

            $cms = [];
            $complete = 0;
            $completionenabled = 0;
            $nextcm = null;
            foreach ((array)($modinfo->sections[$section->section] ?? []) as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if (!$this->is_learning_activity($cm)) {
                    continue;
                }
                $cms[] = $cm;
                if ($completion->is_enabled($cm)) {
                    $completionenabled++;
                    $data = $completion->get_data($cm, false, (int)$USER->id);
                    if ($this->is_completion_done_for_cm($data, $cm)) {
                        $complete++;
                        continue;
                    }
                }
                if ($nextcm === null) {
                    $nextcm = $cm;
                }
            }

            if ($nextcm === null && $cms) {
                $nextcm = reset($cms);
            }

            $options = $format->get_format_options($section);
            $learninggoal = trim((string)($options['learninggoal'] ?? ''));
            $pathkind = (string)($options['pathkind'] ?? 'required');
            $statuskey = $this->section_status_key(count($cms), $completionenabled, $complete);
            $statuslabel = $this->section_status_label(count($cms), $completionenabled, $complete);
            $sectiontitle = trim((string)$section->name) !== '' ? format_string($section->name) :
                get_section_name($course, $section);
            $imageurl = function_exists('format_selfstudy_get_section_image_url') ?
                \format_selfstudy_get_section_image_url($course, (int)$section->id) : null;
            $sectionurl = $nextcm ? $nextcm->url : new \moodle_url('/course/section.php', ['id' => (int)$section->id]);

            $media = $imageurl ?
                \html_writer::empty_tag('img', [
                    'src' => $imageurl->out(false),
                    'alt' => '',
                    'loading' => 'lazy',
                ]) :
                \html_writer::span((string)(int)$section->section, 'format-selfstudy-learningmap-sectionnumber');

            $items[] = \html_writer::tag('article',
                \html_writer::div($media, 'format-selfstudy-learningmap-sectionmedia' .
                    ($imageurl ? '' : ' format-selfstudy-learningmap-sectionmedia-fallback')) .
                \html_writer::div(
                    \html_writer::div(
                        \html_writer::span(get_string($pathkind === 'optional' ? 'optional' : 'required',
                            'format_selfstudy'), 'format-selfstudy-learningmap-sectionchip') .
                        \html_writer::span($statuslabel, 'format-selfstudy-learningmap-sectionstatus'),
                        'format-selfstudy-learningmap-sectionmeta'
                    ) .
                    \html_writer::tag('h3', $sectiontitle) .
                    ($learninggoal !== '' ? \html_writer::div(format_text($learninggoal, FORMAT_PLAIN),
                        'format-selfstudy-learningmap-sectiongoal') : '') .
                    \html_writer::div(get_string('sectionoverviewactivities', 'format_selfstudy', count($cms)),
                        'format-selfstudy-learningmap-sectioncount'),
                    'format-selfstudy-learningmap-sectionbody'
                ) .
                \html_writer::div(
                    \html_writer::link($sectionurl, get_string('learningpathcontinue', 'format_selfstudy'), [
                        'class' => 'btn btn-link format-selfstudy-learningmap-sectionlink',
                    ]),
                    'format-selfstudy-learningmap-sectionaction'
                ),
                ['class' => 'format-selfstudy-learningmap-sectionitem format-selfstudy-learningmap-section-' . $statuskey]
            );
        }

        if (!$items) {
            return '';
        }

        return \html_writer::tag('section',
            \html_writer::div(
                \html_writer::tag('h2', get_string('sectionoverview', 'format_selfstudy')) .
                \html_writer::tag('p', get_string('sectionoverviewintro', 'format_selfstudy')),
                'format-selfstudy-learningmap-sections-head'
            ) .
            \html_writer::div(implode('', $items), 'format-selfstudy-learningmap-sectionlist'),
            ['class' => 'format-selfstudy-learningmap-sections']
        );
    }

    /**
     * Returns whether a CM should appear in section previews.
     *
     * @param \cm_info $cm
     * @return bool
     */
    private function is_learning_activity(\cm_info $cm): bool {
        if (!$cm->uservisible || !empty($cm->deletioninprogress)) {
            return false;
        }
        if (function_exists('format_selfstudy_is_learning_activity')) {
            return \format_selfstudy_is_learning_activity($cm);
        }

        return !in_array($cm->modname, ['label', 'learningmap'], true);
    }

    /**
     * Checks whether a completion state counts as done.
     *
     * @param int $completionstate
     * @return bool
     */
    private function is_completion_done(int $completionstate): bool {
        return $completionstate === COMPLETION_COMPLETE || $completionstate === COMPLETION_COMPLETE_PASS;
    }

    /**
     * Checks whether a course module is complete, including Moodle's view-only completion state.
     *
     * @param \stdClass $data
     * @param \cm_info $cm
     * @return bool
     */
    private function is_completion_done_for_cm(\stdClass $data, \cm_info $cm): bool {
        if ($this->is_completion_done((int)$data->completionstate)) {
            return true;
        }

        return $this->is_view_only_completion($cm) && !empty($data->viewed);
    }

    /**
     * Checks whether the only enabled automatic completion rule is "view activity".
     *
     * @param \cm_info $cm
     * @return bool
     */
    private function is_view_only_completion(\cm_info $cm): bool {
        if ((int)$cm->completion !== COMPLETION_TRACKING_AUTOMATIC ||
                (int)$cm->completionview !== COMPLETION_VIEW_REQUIRED) {
            return false;
        }

        if (!empty($cm->completiongradeitemnumber) || !empty($cm->completionpassgrade)) {
            return false;
        }

        $customdata = (array)$cm->get_custom_data();
        foreach ((array)($customdata['customcompletionrules'] ?? []) as $enabled) {
            if (!empty($enabled)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns a section status key.
     *
     * @param int $activitycount
     * @param int $completionenabled
     * @param int $complete
     * @return string
     */
    private function section_status_key(int $activitycount, int $completionenabled, int $complete): string {
        if ($completionenabled > 0 && $completionenabled === $complete) {
            return 'complete';
        }
        if ($complete > 0) {
            return 'inprogress';
        }
        if ($activitycount > 0) {
            return 'notstarted';
        }
        return 'available';
    }

    /**
     * Returns a section status label.
     *
     * @param int $activitycount
     * @param int $completionenabled
     * @param int $complete
     * @return string
     */
    private function section_status_label(int $activitycount, int $completionenabled, int $complete): string {
        if ($completionenabled > 0 && $completionenabled === $complete) {
            return get_string('statuscomplete', 'format_selfstudy');
        }
        if ($complete > 0) {
            return get_string('statusinprogress', 'format_selfstudy');
        }
        if ($activitycount > 0) {
            return get_string('statusnotstarted', 'format_selfstudy');
        }
        return get_string('statusavailable', 'format_selfstudy');
    }
}
