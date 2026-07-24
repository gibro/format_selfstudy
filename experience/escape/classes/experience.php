<?php
// This file is part of Moodle - http://moodle.org/

namespace selfstudyexperience_escape;

defined('MOODLE_INTERNAL') || die();

/**
 * Metadata provider for the Escape selfstudy experience.
 *
 * This is a package-internal learner view, not a Moodle plugin. It stages the
 * existing learning path as an escape game: milestones become rooms, stations
 * become hotspots and Moodle availability decides which rooms are still locked.
 * The component string is kept stable because course data references it.
 */
class experience {

    /**
     * Returns component metadata for the selfstudy experience registry.
     *
     * @return \stdClass
     */
    public static function get_metadata(): \stdClass {
        return (object)[
            'component' => 'selfstudyexperience_escape',
            'name' => get_string('escapeexperience_name', 'format_selfstudy'),
            'description' => get_string('escapeexperience_plugindescription', 'format_selfstudy'),
            'icon' => 'i/lock',
            'schema' => 1,
            'features' => ['escape'],
            'rendererclass' => '\\selfstudyexperience_escape\\renderer',
            'configformclass' => '\\selfstudyexperience_escape\\config_form',
        ];
    }
}
