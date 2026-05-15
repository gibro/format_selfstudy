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
            return \html_writer::div(get_string('setupmissing', 'selfstudyexperience_learningmap'),
                'alert alert-warning');
        }

        $mapmodel = (new map_builder())->build($course, $baseview, $config);
        $mapurl = $adapter->get_map_url($cm, $mapmodel);
        $sync = $adapter->describe_sync($cm, $mapmodel);
        $modeljson = json_encode($mapmodel, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return \html_writer::tag('section',
            \html_writer::tag('h2', get_string('pluginname', 'selfstudyexperience_learningmap')) .
            \html_writer::div($this->render_status_summary($mapmodel, $sync),
                'format-selfstudy-learningmap-summary') .
            \html_writer::link($mapurl, get_string('openlearningmap', 'selfstudyexperience_learningmap'), [
                'class' => 'btn btn-primary format-selfstudy-learningmap-open',
            ]),
            [
                'class' => 'format-selfstudy-learningmap-experience format-selfstudy-learningmap-theme-' .
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
     * @param \stdClass $sync
     * @return string
     */
    private function render_status_summary(\stdClass $mapmodel, \stdClass $sync): string {
        $counts = [];
        foreach ((array)($mapmodel->nodes ?? []) as $node) {
            $state = (string)($node->gamestate ?? 'available');
            $counts[$state] = ($counts[$state] ?? 0) + 1;
        }

        $items = [];
        foreach ($counts as $state => $count) {
            $items[] = \html_writer::span(s($state) . ': ' . (int)$count,
                'format-selfstudy-learningmap-state');
        }

        $items[] = \html_writer::span(get_string('revision', 'selfstudyexperience_learningmap') . ': ' .
            (int)$sync->revision, 'format-selfstudy-learningmap-state');

        return implode('', $items);
    }
}
