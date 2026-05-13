<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Central rules for deciding which course modules can be selfstudy path stations.
 */
class activity_filter {

    /** @var string[] Module types that are not learner path stations. */
    private const EXCLUDED_STATION_MODULES = [
        'learningmap',
        'qbank',
    ];

    /**
     * Returns whether the module type should be excluded from path station use.
     *
     * @param \cm_info $cm
     * @return bool
     */
    public static function is_excluded_station_module(\cm_info $cm): bool {
        return in_array($cm->modname, self::EXCLUDED_STATION_MODULES, true);
    }

    /**
     * Returns whether a course module can be used as a learning path station.
     *
     * @param \cm_info $cm
     * @param bool $requirevisible
     * @return bool
     */
    public static function is_learning_station(\cm_info $cm, bool $requirevisible = false): bool {
        if ($requirevisible && !$cm->uservisible) {
            return false;
        }

        if (empty($cm->url)) {
            return false;
        }

        return !self::is_excluded_station_module($cm);
    }
}
