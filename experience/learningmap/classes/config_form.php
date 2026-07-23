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

        if (count($mapoptions) <= 1) {
            $output .= \html_writer::div(get_string('learningmapexperience_nolearningmapactivity', 'format_selfstudy'),
                'format-selfstudy-patheditor-activitysettingsnotice');
        }

        $output .= \html_writer::start_div('format-selfstudy-settings-fieldgrid');
        $output .= \html_writer::div(
            \html_writer::label(get_string('learningmapexperience_learningmapactivity', 'format_selfstudy'),
                $prefix . '_learningmapcmid') .
            \html_writer::select($mapoptions, $prefix . '[learningmapcmid]',
                (int)($config->learningmapcmid ?? 0), false, [
                    'id' => $prefix . '_learningmapcmid',
                    'class' => 'custom-select form-control',
                ]),
            'format-selfstudy-settings-field'
        );
        $output .= \html_writer::div(
            \html_writer::label(get_string('learningmapexperience_theme', 'format_selfstudy'), $prefix . '_theme') .
            \html_writer::select([
                'adventure' => get_string('learningmapexperience_themeadventure', 'format_selfstudy'),
                'quiet' => get_string('learningmapexperience_themequiet', 'format_selfstudy'),
            ], $prefix . '[theme]', (string)($config->theme ?? 'adventure'), false, [
                'id' => $prefix . '_theme',
                'class' => 'custom-select form-control',
            ]),
            'format-selfstudy-settings-field'
        );
        $output .= self::checkbox($prefix, 'avatarenabled',
            get_string('learningmapexperience_avatarenabled', 'format_selfstudy'),
            !isset($config->avatarenabled) || !empty($config->avatarenabled));
        $output .= self::checkbox($prefix, 'routeenabled',
            get_string('learningmapexperience_routeenabled', 'format_selfstudy'),
            !isset($config->routeenabled) || !empty($config->routeenabled));
        $output .= self::checkbox($prefix, 'milestonebadgesenabled',
            get_string('learningmapexperience_milestonebadgesenabled', 'format_selfstudy'),
            !isset($config->milestonebadgesenabled) ||
                !empty($config->milestonebadgesenabled));
        $output .= \html_writer::end_div();

        $output .= self::render_mapping_panel($course, $config);

        $output .= \html_writer::end_div();
        return $output;
    }

    /**
     * Renders teacher-only mapping actions for the selected Learningmap activity.
     *
     * @param \stdClass $course
     * @param \stdClass $config
     * @return string
     */
    private static function render_mapping_panel(\stdClass $course, \stdClass $config): string {
        global $USER;

        if (empty($config->learningmapcmid)) {
            return '';
        }

        require_once(__DIR__ . '/map_builder.php');
        require_once(__DIR__ . '/learningmap_adapter.php');

        $adapter = new learningmap_adapter();
        $cm = $adapter->get_configured_cm($course, $config);
        if (!$cm) {
            return '';
        }

        $baseview = \format_selfstudy\local\base_view::create($course, (int)$USER->id);
        $mapmodel = (new map_builder())->build($course, $baseview, $config);
        $status = $adapter->get_mapping_status($cm, $mapmodel);

        $items = [
            get_string('learningmapexperience_mappingmapped', 'format_selfstudy',
                (object)['mapped' => $status->mappedcount, 'total' => $status->nodecount]),
        ];
        if ($status->missingcount > 0) {
            $items[] = get_string('learningmapexperience_mappingmissing', 'format_selfstudy', $status->missingcount);
        }
        if ($status->orphanedcount > 0) {
            $items[] = get_string('learningmapexperience_mappingorphaned', 'format_selfstudy', $status->orphanedcount);
        }

        $settingsurl = new \moodle_url('/course/modedit.php', [
            'update' => (int)$cm->id,
            'return' => 1,
        ]);

        return \html_writer::div(
            \html_writer::div(implode(\html_writer::span(' · ', 'format-selfstudy-learningmap-separator'), $items),
                'format-selfstudy-learningmap-mappingstatus') .
            \html_writer::div(get_string('learningmapexperience_mappinghelp', 'format_selfstudy'),
                'format-selfstudy-learningmap-mappinghelp') .
            \html_writer::div(
                \html_writer::link($settingsurl, get_string('learningmapexperience_mappingopensettings', 'format_selfstudy'),
                    ['class' => 'btn btn-secondary']),
                'format-selfstudy-learningmap-mappingactions'
            ),
            'format-selfstudy-learningmap-mappingpanel'
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

        return [
            'learningmapcmid' => max(0, (int)($submitted['learningmapcmid'] ?? 0)),
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
        $options = [0 => get_string('learningmapexperience_selectlearningmapactivity', 'format_selfstudy')];

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
