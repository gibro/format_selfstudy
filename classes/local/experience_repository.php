<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Persistence API for optional selfstudy experiences.
 */
class experience_repository {

    /** @var string Experience configuration table. */
    public const TABLE = 'format_selfstudy_experiences';

    /**
     * Returns all stored experience configurations for a course.
     *
     * @param int $courseid
     * @return \stdClass[]
     */
    public function get_course_experiences(int $courseid): array {
        global $DB;

        return array_values($DB->get_records(self::TABLE, ['courseid' => $courseid],
            'sortorder ASC, id ASC'));
    }

    /**
     * Returns enabled stored experience configurations for a course.
     *
     * @param int $courseid
     * @return \stdClass[]
     */
    public function get_enabled_course_experiences(int $courseid): array {
        global $DB;

        return array_values($DB->get_records(self::TABLE, [
            'courseid' => $courseid,
            'enabled' => 1,
            'missing' => 0,
        ], 'sortorder ASC, id ASC'));
    }

    /**
     * Returns one stored experience configuration.
     *
     * @param int $courseid
     * @param string $component
     * @return \stdClass|null
     */
    public function get_course_experience(int $courseid, string $component): ?\stdClass {
        global $DB;

        $record = $DB->get_record(self::TABLE, [
            'courseid' => $courseid,
            'component' => $this->normalise_component($component),
        ], '*', IGNORE_MISSING);

        return $record ?: null;
    }

    /**
     * Creates or updates one experience configuration.
     *
     * @param int $courseid
     * @param string $component
     * @param array|\stdClass $config
     * @param bool $enabled
     * @param int $sortorder
     * @param int $configschema
     * @param bool $missing
     */
    public function save_course_experience(int $courseid, string $component, $config = [], bool $enabled = true,
            int $sortorder = 0, int $configschema = 1, bool $missing = false): void {
        global $DB;

        $component = $this->normalise_component($component);
        if ($component === '') {
            throw new \coding_exception('Experience component must not be empty.');
        }

        $existing = $this->get_course_experience($courseid, $component);
        $now = time();
        $record = (object)[
            'courseid' => $courseid,
            'component' => $component,
            'enabled' => $enabled ? 1 : 0,
            'sortorder' => $sortorder,
            'configjson' => $this->encode_config($config),
            'configschema' => max(1, $configschema),
            'missing' => $missing ? 1 : 0,
            'timemodified' => $now,
        ];

        if ($existing) {
            $record->id = (int)$existing->id;
            $record->timecreated = (int)$existing->timecreated;
            $DB->update_record(self::TABLE, $record);
            return;
        }

        $record->timecreated = $now;
        $DB->insert_record(self::TABLE, $record);
    }

    /**
     * Marks stored entries as missing when their component is not installed.
     *
     * @param int $courseid
     * @param array $installedcomponents
     */
    public function mark_missing_experiences(int $courseid, array $installedcomponents): void {
        global $DB;

        $installed = array_flip(array_map([$this, 'normalise_component'], $installedcomponents));
        foreach ($this->get_course_experiences($courseid) as $record) {
            $ismissing = empty($installed[$record->component]);
            if ((int)$record->missing === (int)$ismissing) {
                continue;
            }
            $record->missing = $ismissing ? 1 : 0;
            $record->timemodified = time();
            $DB->update_record(self::TABLE, $record);
        }
    }

    /**
     * Decodes a stored JSON configuration object.
     *
     * @param \stdClass $record
     * @return \stdClass
     */
    public function decode_config(\stdClass $record): \stdClass {
        $decoded = json_decode((string)($record->configjson ?? '{}'));
        if (!$decoded instanceof \stdClass || json_last_error() !== JSON_ERROR_NONE) {
            return (object)[];
        }
        return $decoded;
    }

    /**
     * Encodes an experience config object.
     *
     * @param array|\stdClass $config
     * @return string
     */
    private function encode_config($config): string {
        if ($config instanceof \stdClass) {
            $config = (array)$config;
        }
        if (!is_array($config)) {
            $config = [];
        }

        $json = json_encode((object)$config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json !== false ? $json : '{}';
    }

    /**
     * Normalises component names.
     *
     * @param string $component
     * @return string
     */
    private function normalise_component(string $component): string {
        return clean_param($component, PARAM_COMPONENT);
    }
}
