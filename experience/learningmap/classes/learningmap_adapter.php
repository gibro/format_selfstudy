<?php
// This file is part of Moodle - http://moodle.org/

namespace selfstudyexperience_learningmap;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only adapter boundary for mod_learningmap.
 *
 * Teachers curate places and paths in the Learningmap editor. The selfstudy
 * experience only links to that activity and reports mapping coverage.
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
     * Returns the configured Learningmap background image URL.
     *
     * @param \cm_info $cm
     * @return \moodle_url|null
     */
    public function get_background_image_url(\cm_info $cm): ?\moodle_url {
        $context = \context_module::instance((int)$cm->id);
        $files = get_file_storage()->get_area_files($context->id, 'mod_learningmap', 'background', 0,
            'sortorder, id', false);
        $file = reset($files);
        if (!$file) {
            return null;
        }

        return \moodle_url::make_pluginfile_url($context->id, 'mod_learningmap', 'background', 0, '/',
            $file->get_filename(), false);
    }

    /**
     * Describes the selected Learningmap activity and neutral path model.
     *
     * @param \cm_info $cm
     * @param \stdClass $mapmodel
     * @return \stdClass
     */
    public function describe_context(\cm_info $cm, \stdClass $mapmodel): \stdClass {
        return (object)[
            'cmid' => (int)$cm->id,
            'nodecount' => count((array)($mapmodel->nodes ?? [])),
            'connectioncount' => count((array)($mapmodel->connections ?? [])),
            'source' => (string)($mapmodel->source ?? ''),
            'revision' => (int)($mapmodel->revision ?? 0),
        ];
    }

    /**
     * Analyses how well the configured Learningmap covers the published selfstudy path.
     *
     * @param \cm_info $cm
     * @param \stdClass $mapmodel
     * @return \stdClass
     */
    public function get_mapping_status(\cm_info $cm, \stdClass $mapmodel): \stdClass {
        global $DB;

        $record = $DB->get_record('learningmap', ['id' => (int)$cm->instance], 'id, placestore', IGNORE_MISSING);
        $placestore = $record ? json_decode((string)$record->placestore, true) : null;
        if (!is_array($placestore)) {
            $placestore = [];
        }

        $pathcmids = [];
        $pathcmidlist = [];
        foreach ((array)($mapmodel->nodes ?? []) as $node) {
            $cmid = (int)($node->cmid ?? 0);
            if ($cmid > 0) {
                $pathcmids[$cmid] = true;
                $pathcmidlist[] = $cmid;
            }
        }

        $mappedcmids = [];
        foreach ((array)($placestore['places'] ?? []) as $place) {
            $cmid = (int)($place['linkedActivity'] ?? 0);
            if ($cmid > 0) {
                $mappedcmids[$cmid] = true;
            }
        }

        $missing = array_values(array_diff(array_keys($pathcmids), array_keys($mappedcmids)));
        $orphaned = array_values(array_diff(array_keys($mappedcmids), array_keys($pathcmids)));
        $placecount = count((array)($placestore['places'] ?? []));

        return (object)[
            'cmid' => (int)$cm->id,
            'nodecount' => count($pathcmidlist),
            'placecount' => $placecount,
            'mappedcount' => count(array_filter($pathcmidlist, static function(int $cmid) use ($mappedcmids): bool {
                return isset($mappedcmids[$cmid]);
            })),
            'missingcount' => count($missing),
            'orphanedcount' => count($orphaned),
            'missingcmids' => $missing,
            'orphanedcmids' => $orphaned,
            'hasmap' => $placecount > 0,
        ];
    }

}
