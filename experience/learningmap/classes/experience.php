<?php
// This file is part of Moodle - http://moodle.org/

namespace selfstudyexperience_learningmap;

defined('MOODLE_INTERNAL') || die();

/**
 * Metadata provider for the Learningmap selfstudy experience.
 *
 * This is a package-internal learner view, not a Moodle plugin. The component
 * string below is the key the registry and the stored configuration use; it is
 * deliberately kept stable because course data references it.
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
