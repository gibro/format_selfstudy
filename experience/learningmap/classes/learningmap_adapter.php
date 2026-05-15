<?php
// This file is part of Moodle - http://moodle.org/

namespace selfstudyexperience_learningmap;

defined('MOODLE_INTERNAL') || die();

/**
 * Adapter boundary for mod_learningmap.
 */
class learningmap_adapter {

    /**
     * Returns the configured visible Learningmap course module.
     *
     * @param \stdClass $course
     * @param \stdClass $config
     * @return \cm_info|null
     */
    public function get_configured_cm(\stdClass $course, \stdClass $config): ?\cm_info {
        $cmid = (int)($config->learningmapcmid ?? 0);
        if ($cmid <= 0) {
            return null;
        }

        try {
            $cm = get_fast_modinfo($course)->get_cm($cmid);
        } catch (\Throwable $exception) {
            return null;
        }

        if ((int)$cm->course !== (int)$course->id || $cm->modname !== 'learningmap' || !$cm->uservisible) {
            return null;
        }

        return $cm;
    }

    /**
     * Builds a URL to the configured Learningmap CM.
     *
     * @param \cm_info $cm
     * @param \stdClass|null $mapmodel
     * @return \moodle_url
     */
    public function get_map_url(\cm_info $cm, ?\stdClass $mapmodel = null): \moodle_url {
        $params = ['id' => (int)$cm->id];
        if ($mapmodel && !empty($mapmodel->currentkey)) {
            $params['selfstudycurrent'] = (string)$mapmodel->currentkey;
        }

        return new \moodle_url('/mod/learningmap/view.php', $params);
    }

    /**
     * Prepares sync metadata for a future mod_learningmap write adapter.
     *
     * @param \cm_info $cm
     * @param \stdClass $mapmodel
     * @return \stdClass
     */
    public function describe_sync(\cm_info $cm, \stdClass $mapmodel): \stdClass {
        return (object)[
            'cmid' => (int)$cm->id,
            'nodecount' => count((array)($mapmodel->nodes ?? [])),
            'connectioncount' => count((array)($mapmodel->connections ?? [])),
            'source' => (string)($mapmodel->source ?? ''),
            'revision' => (int)($mapmodel->revision ?? 0),
        ];
    }
}
