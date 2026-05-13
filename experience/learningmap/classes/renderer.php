<?php
// This file is part of Moodle - http://moodle.org/

namespace selfstudyexperience_learningmap;

use format_selfstudy\local\experience_renderer_interface;
use format_selfstudy\local\learningmap_config_migrator;

defined('MOODLE_INTERNAL') || die();

/**
 * Learner-facing renderer for configured Learningmap activities.
 */
class renderer implements experience_renderer_interface {

    /**
     * Returns whether the renderer can be used for this course.
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
     * Renders the course entry links for the configured maps.
     *
     * @param \stdClass $course
     * @param \stdClass $baseview
     * @param \stdClass $config
     * @return string
     */
    public function render_course_entry(\stdClass $course, \stdClass $baseview, \stdClass $config): string {
        try {
            $modinfo = get_fast_modinfo($course);
        } catch (\Throwable $exception) {
            return '';
        }

        $mainmapcm = learningmap_config_migrator::resolve_main_map_cm($modinfo, $config);
        if ($mainmapcm) {
            return \html_writer::link($mainmapcm->url,
                get_string('learningmapopenmain', 'format_selfstudy'),
                ['class' => 'btn btn-secondary format-selfstudy-learningmap-entry']);
        }

        if (empty($config->sectionmapsenabled) || empty($config->sectionmaps)) {
            return '';
        }

        $links = [];
        foreach ((array)$config->sectionmaps as $sectionid => $cmid) {
            $cm = learningmap_config_migrator::resolve_section_map_cm($modinfo, $config, (int)$sectionid);
            if (!$cm) {
                continue;
            }
            $links[] = \html_writer::tag('li',
                \html_writer::link($cm->url, format_string($cm->name), [
                    'class' => 'btn btn-secondary btn-sm format-selfstudy-learningmap-sectionentry',
                ])
            );
        }

        if (!$links) {
            return '';
        }

        return \html_writer::tag('h3', get_string('learningmapsectionmaps', 'format_selfstudy')) .
            \html_writer::tag('ul', implode('', $links), ['class' => 'format-selfstudy-learningmap-sectionentries']);
    }

    /**
     * Returns the best Learningmap URL for an activity page.
     *
     * @param \stdClass $course
     * @param \stdClass $baseview
     * @param \cm_info $cm
     * @param \stdClass $config
     * @return \stdClass|null
     */
    public function get_activity_navigation(\stdClass $course, \stdClass $baseview, \cm_info $cm,
            \stdClass $config): ?\stdClass {
        try {
            $modinfo = get_fast_modinfo($course);
        } catch (\Throwable $exception) {
            return null;
        }

        $mapcm = null;
        $sectionid = $this->get_cm_section_id($modinfo, $cm);
        if ($sectionid) {
            $mapcm = learningmap_config_migrator::resolve_section_map_cm($modinfo, $config, $sectionid);
        }
        if (!$mapcm) {
            $mapcm = learningmap_config_migrator::resolve_main_map_cm($modinfo, $config);
        }

        if (!$mapcm || empty($mapcm->url)) {
            return null;
        }

        return (object)[
            'mapurl' => $mapcm->url->out(false),
        ];
    }

    /**
     * Finds the course section id that contains a CM.
     *
     * @param \course_modinfo $modinfo
     * @param \cm_info $cm
     * @return int
     */
    private function get_cm_section_id(\course_modinfo $modinfo, \cm_info $cm): int {
        foreach ($modinfo->get_section_info_all() as $section) {
            if (empty($modinfo->sections[$section->section])) {
                continue;
            }
            if (in_array((int)$cm->id, array_map('intval', $modinfo->sections[$section->section]), true)) {
                return (int)$section->id;
            }
        }

        return 0;
    }
}
