<?php
// This file is part of Moodle - http://moodle.org/

namespace selfstudyexperience_learningmap;

defined('MOODLE_INTERNAL') || die();

/**
 * Configuration controls for the Learningmap selfstudy experience.
 */
class config_form {

    /**
     * Renders the experience-specific course settings controls.
     *
     * @param \stdClass $course
     * @param \stdClass $config
     * @param string $prefix
     * @return string
     */
    public static function render(\stdClass $course, \stdClass $config, string $prefix): string {
        $mapoptions = self::get_learningmap_options($course);

        $output = \html_writer::start_div('format-selfstudy-settings-subpanel');
        $output .= \html_writer::tag('h5', get_string('pluginname', 'selfstudyexperience_learningmap'));
        $output .= \html_writer::div(get_string('configintro', 'selfstudyexperience_learningmap'),
            'format-selfstudy-settings-help');

        if (count($mapoptions) <= 1) {
            $output .= \html_writer::div(get_string('nolearningmapactivity', 'selfstudyexperience_learningmap'),
                'format-selfstudy-patheditor-activitysettingsnotice');
        }

        $output .= \html_writer::start_div('format-selfstudy-settings-fieldgrid');
        $output .= \html_writer::div(
            \html_writer::label(get_string('learningmapactivity', 'selfstudyexperience_learningmap'),
                $prefix . '_learningmapcmid') .
            \html_writer::select($mapoptions, $prefix . '[learningmapcmid]',
                (int)($config->learningmapcmid ?? 0), false, [
                    'id' => $prefix . '_learningmapcmid',
                    'class' => 'custom-select form-control',
                ]),
            'format-selfstudy-settings-field'
        );
        $output .= \html_writer::div(
            \html_writer::label(get_string('theme', 'selfstudyexperience_learningmap'), $prefix . '_theme') .
            \html_writer::select([
                'adventure' => get_string('themeadventure', 'selfstudyexperience_learningmap'),
                'quiet' => get_string('themequiet', 'selfstudyexperience_learningmap'),
            ], $prefix . '[theme]', (string)($config->theme ?? 'adventure'), false, [
                'id' => $prefix . '_theme',
                'class' => 'custom-select form-control',
            ]),
            'format-selfstudy-settings-field'
        );
        $output .= self::checkbox($prefix, 'avatarenabled', get_string('avatarenabled',
            'selfstudyexperience_learningmap'), !isset($config->avatarenabled) || !empty($config->avatarenabled));
        $output .= self::checkbox($prefix, 'routeenabled', get_string('routeenabled',
            'selfstudyexperience_learningmap'), !isset($config->routeenabled) || !empty($config->routeenabled));
        $output .= self::checkbox($prefix, 'milestonebadgesenabled', get_string('milestonebadgesenabled',
            'selfstudyexperience_learningmap'), !isset($config->milestonebadgesenabled) ||
                !empty($config->milestonebadgesenabled));
        $output .= \html_writer::end_div();

        $output .= \html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => $prefix . '[syncmode]',
            'value' => 'snapshot',
        ]);

        $output .= \html_writer::end_div();
        return $output;
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

        return [
            'learningmapcmid' => max(0, (int)($submitted['learningmapcmid'] ?? 0)),
            'syncmode' => 'snapshot',
            'theme' => in_array((string)($submitted['theme'] ?? 'adventure'), ['adventure', 'quiet'], true) ?
                (string)$submitted['theme'] : 'adventure',
            'avatarenabled' => !empty($submitted['avatarenabled']),
            'routeenabled' => !empty($submitted['routeenabled']),
            'milestonebadgesenabled' => !empty($submitted['milestonebadgesenabled']),
        ];
    }

    /**
     * Renders a checkbox field.
     *
     * @param string $prefix
     * @param string $name
     * @param string $label
     * @param bool $checked
     * @return string
     */
    private static function checkbox(string $prefix, string $name, string $label, bool $checked): string {
        return \html_writer::div(
            \html_writer::label(
                \html_writer::empty_tag('input', [
                    'type' => 'checkbox',
                    'name' => $prefix . '[' . $name . ']',
                    'value' => 1,
                ] + ($checked ? ['checked' => 'checked'] : [])) .
                \html_writer::span($label),
                ''
            ),
            'format-selfstudy-settings-choice'
        );
    }

    /**
     * Returns visible mod_learningmap activities.
     *
     * @param \stdClass $course
     * @return array
     */
    private static function get_learningmap_options(\stdClass $course): array {
        $options = [0 => get_string('selectlearningmapactivity', 'selfstudyexperience_learningmap')];

        try {
            $modinfo = get_fast_modinfo($course);
        } catch (\Throwable $exception) {
            return $options;
        }

        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->modname === 'learningmap' && $cm->uservisible) {
                $options[(int)$cm->id] = format_string($cm->name, true);
            }
        }

        return $options;
    }
}
