<?php
// This file is part of Moodle - http://moodle.org/

namespace selfstudyexperience_escape;

defined('MOODLE_INTERNAL') || die();

/**
 * Configuration controls for the Escape selfstudy experience.
 */
class config_form {

    /**
     * Describes the configuration fields for the course settings form.
     *
     * The core builds real form elements from this and hides them unless the
     * escape view is the selected one, so teachers only see settings that
     * belong to their choice. The rooms themselves come from the path
     * milestones and are not configured here.
     *
     * @param \stdClass $course
     * @return array field name => definition
     */
    public static function get_field_definitions(\stdClass $course): array {
        return [
            'theme' => [
                'type' => 'select',
                'label' => get_string('escapeexperience_theme', 'format_selfstudy'),
                'options' => self::get_theme_options(),
                'param' => PARAM_ALPHA,
                'default' => 'dungeon',
            ],
            'introstory' => [
                'type' => 'textarea',
                'label' => get_string('escapeexperience_introstory', 'format_selfstudy'),
                'param' => PARAM_TEXT,
                'default' => '',
            ],
            'showinventory' => [
                'type' => 'advcheckbox',
                'label' => get_string('escapeexperience_showinventory', 'format_selfstudy'),
                'param' => PARAM_BOOL,
                'default' => 1,
            ],
            'revealmystery' => [
                'type' => 'advcheckbox',
                'label' => get_string('escapeexperience_revealmystery', 'format_selfstudy'),
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
        $guideurl = new \moodle_url('/course/format/selfstudy/escape_guide.php', ['id' => (int)$course->id]);

        return \html_writer::div(get_string('escapeexperience_roomsnote', 'format_selfstudy'),
            'format-selfstudy-patheditor-activitysettingsnotice') .
            \html_writer::div(
                \html_writer::link($guideurl, get_string('escapeguide_title', 'format_selfstudy'),
                    ['class' => 'btn btn-secondary']),
                'format-selfstudy-escapeguide-formlink'
            );
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

        $theme = (string)($submitted['theme'] ?? 'dungeon');
        if (!array_key_exists($theme, self::get_theme_options())) {
            $theme = 'dungeon';
        }

        return [
            'theme' => $theme,
            'introstory' => clean_param((string)($submitted['introstory'] ?? ''), PARAM_TEXT),
            'showinventory' => !empty($submitted['showinventory']),
            'revealmystery' => !empty($submitted['revealmystery']),
        ];
    }

    /**
     * Returns the available escape themes.
     *
     * @return array
     */
    private static function get_theme_options(): array {
        return [
            'dungeon' => get_string('escapeexperience_themedungeon', 'format_selfstudy'),
            'spaceship' => get_string('escapeexperience_themespaceship', 'format_selfstudy'),
            'mansion' => get_string('escapeexperience_thememansion', 'format_selfstudy'),
        ];
    }
}
