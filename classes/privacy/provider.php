<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy provider for the self-directed learning course format.
 */
class provider implements \core_privacy\local\metadata\null_provider {

    /**
     * Returns a reason why this plugin stores no personal data.
     *
     * @return string
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
