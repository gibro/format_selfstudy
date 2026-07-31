<?php
// This file is part of Moodle - http://moodle.org/

namespace selfstudyexperience_nuggets;

defined('MOODLE_INTERNAL') || die();

/**
 * Configuration controls for the Learning-Nuggets selfstudy experience.
 */
class config_form {

    /**
     * Describes the configuration fields for the course settings form.
     *
     * The core builds real form elements from this and hides them unless the
     * nuggets view is the selected one. The nuggets themselves come from the
     * published learning path and are not configured here.
     *
     * @param \stdClass $course
     * @return array field name => definition
     */
    public static function get_field_definitions(\stdClass $course): array {
        return [
            'mode' => [
                'type' => 'select',
                'label' => get_string('nuggetsexperience_mode', 'format_selfstudy'),
                'options' => self::get_mode_options(),
                'param' => PARAM_ALPHA,
                'default' => 'feed',
            ],
            'navmodel' => [
                'type' => 'select',
                'label' => get_string('nuggetsexperience_navmodel', 'format_selfstudy'),
                'options' => self::get_navmodel_options(),
                'param' => PARAM_ALPHA,
                'default' => 'snap',
            ],
            'lockedvisibility' => [
                'type' => 'select',
                'label' => get_string('nuggetsexperience_lockedvisibility', 'format_selfstudy'),
                'options' => self::get_lockedvisibility_options(),
                'param' => PARAM_ALPHA,
                'default' => 'marked',
            ],
            'introtext' => [
                'type' => 'textarea',
                'label' => get_string('nuggetsexperience_introtext', 'format_selfstudy'),
                'param' => PARAM_TEXT,
                'default' => '',
            ],
            'inlineembed' => [
                'type' => 'advcheckbox',
                'label' => get_string('nuggetsexperience_inlineembed', 'format_selfstudy'),
                'param' => PARAM_BOOL,
                'default' => 1,
            ],
            'focusmode' => [
                'type' => 'advcheckbox',
                'label' => get_string('nuggetsexperience_focusmode', 'format_selfstudy'),
                'param' => PARAM_BOOL,
                'default' => 1,
            ],
            'scrollcompletion' => [
                'type' => 'advcheckbox',
                'label' => get_string('nuggetsexperience_scrollcompletion', 'format_selfstudy'),
                'param' => PARAM_BOOL,
                'default' => 1,
            ],
            'manualcompletion' => [
                'type' => 'advcheckbox',
                'label' => get_string('nuggetsexperience_manualcompletion', 'format_selfstudy'),
                'param' => PARAM_BOOL,
                'default' => 1,
            ],
            'autoscroll' => [
                'type' => 'advcheckbox',
                'label' => get_string('nuggetsexperience_autoscroll', 'format_selfstudy'),
                'param' => PARAM_BOOL,
                'default' => 1,
            ],
        ];
    }

    /**
     * Returns teacher-facing notes shown below the fields in the course settings.
     *
     * @param \stdClass $course
     * @param \stdClass $config
     * @return string
     */
    public static function get_form_notes(\stdClass $course, \stdClass $config): string {
        return \html_writer::div(get_string('nuggetsexperience_confignote', 'format_selfstudy'),
            'format-selfstudy-patheditor-activitysettingsnotice');
    }

    /**
     * Reads the submitted experience-specific config.
     *
     * @param string $prefix
     * @param array|\stdClass $fallback
     * @return array
     */
    public static function get_config_from_request(string $prefix, $fallback = []): array {
        $submitted = optional_param_array($prefix, [], PARAM_RAW);
        if (!$submitted) {
            return is_array($fallback) ? $fallback : (array)$fallback;
        }

        $mode = (string)($submitted['mode'] ?? 'feed');
        if (!array_key_exists($mode, self::get_mode_options())) {
            $mode = 'feed';
        }

        $navmodel = (string)($submitted['navmodel'] ?? 'snap');
        if (!array_key_exists($navmodel, self::get_navmodel_options())) {
            $navmodel = 'snap';
        }

        $lockedvisibility = (string)($submitted['lockedvisibility'] ?? 'marked');
        if (!array_key_exists($lockedvisibility, self::get_lockedvisibility_options())) {
            $lockedvisibility = 'marked';
        }

        return [
            'mode' => $mode,
            'navmodel' => $navmodel,
            'lockedvisibility' => $lockedvisibility,
            'introtext' => clean_param((string)($submitted['introtext'] ?? ''), PARAM_TEXT),
            'inlineembed' => !empty($submitted['inlineembed']),
            'focusmode' => !empty($submitted['focusmode']),
            'scrollcompletion' => !empty($submitted['scrollcompletion']),
            'manualcompletion' => !empty($submitted['manualcompletion']),
            'autoscroll' => !empty($submitted['autoscroll']),
        ];
    }

    /**
     * Returns the available display modes.
     *
     * Only the linear feed exists yet; the select keeps courses stable once
     * further modes (collection, decision track, timed series) arrive.
     *
     * @return array
     */
    private static function get_mode_options(): array {
        return [
            'feed' => get_string('nuggetsexperience_modefeed', 'format_selfstudy'),
        ];
    }

    /**
     * Returns the navigation models of the full-screen app.
     *
     * @return array
     */
    private static function get_navmodel_options(): array {
        return [
            'snap' => get_string('nuggetsexperience_navsnap', 'format_selfstudy'),
            'story' => get_string('nuggetsexperience_navstory', 'format_selfstudy'),
        ];
    }

    /**
     * Returns the display choices for locked nuggets.
     *
     * @return array
     */
    private static function get_lockedvisibility_options(): array {
        return [
            'marked' => get_string('nuggetsexperience_lockedmarked', 'format_selfstudy'),
            'reduced' => get_string('nuggetsexperience_lockedreduced', 'format_selfstudy'),
            'hidden' => get_string('nuggetsexperience_lockedhidden', 'format_selfstudy'),
        ];
    }
}
