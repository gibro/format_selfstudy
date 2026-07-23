<?php
// This file is part of Moodle - http://moodle.org/

namespace selfstudyexperience_learningmap;

defined('MOODLE_INTERNAL') || die();

/**
 * Metadata provider for the Learningmap selfstudy experience.
 */
class experience {

    /**
     * Returns component metadata for the selfstudy experience registry.
     *
     * @return \stdClass
     */
    public static function get_metadata(): \stdClass {
        return (object)[
            'component' => 'selfstudyexperience_learningmap',
            'name' => get_string('learningmapexperience_name', 'format_selfstudy'),
            'description' => get_string('learningmapexperience_plugindescription', 'format_selfstudy'),
            'icon' => 'i/navigationitem',
            'schema' => 1,
            'features' => ['map', 'activitynavigation'],
            'rendererclass' => '\\selfstudyexperience_learningmap\\renderer',
            'configformclass' => '\\selfstudyexperience_learningmap\\config_form',
        ];
    }
}
