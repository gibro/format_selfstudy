<?php
// This file is part of Moodle - http://moodle.org/

namespace selfstudyexperience_nuggets;

defined('MOODLE_INTERNAL') || die();

/**
 * Metadata provider for the Learning-Nuggets selfstudy experience.
 *
 * This is a package-internal learner view, not a Moodle plugin. It stages the
 * published learning path as a series of compact learning nuggets. The first
 * display mode is a linear scroll feed; further modes (collection, decision
 * track, timed series) are planned to reuse the same nugget model.
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
            'component' => 'selfstudyexperience_nuggets',
            'name' => get_string('nuggetsexperience_name', 'format_selfstudy'),
            'description' => get_string('nuggetsexperience_plugindescription', 'format_selfstudy'),
            'icon' => 'i/log',
            'schema' => 1,
            'features' => ['feed'],
            'rendererclass' => '\\selfstudyexperience_nuggets\\renderer',
            'configformclass' => '\\selfstudyexperience_nuggets\\config_form',
        ];
    }
}
