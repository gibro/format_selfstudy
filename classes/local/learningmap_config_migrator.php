<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Collects and mirrors legacy Learningmap format settings.
 */
class learningmap_config_migrator {

    /** @var string Learningmap experience component. */
    public const COMPONENT = 'selfstudyexperience_learningmap';

    /** @var int Current Learningmap experience config schema. */
    public const SCHEMA = 1;

    /** @var experience_repository */
    private $repository;

    /**
     * Constructor.
     *
     * @param experience_repository|null $repository
     */
    public function __construct(?experience_repository $repository = null) {
        $this->repository = $repository ?? new experience_repository();
    }

    /**
     * Mirrors legacy settings for all selfstudy courses.
     *
     * @return int Number of mirrored courses.
     */
    public function mirror_all_courses(): int {
        global $DB;

        $count = 0;
        $courses = $DB->get_records('course', ['format' => 'selfstudy'], '', 'id');
        foreach ($courses as $course) {
            if ($this->mirror_course((int)$course->id)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Mirrors legacy Learningmap settings for one course into the experience table.
     *
     * @param int $courseid
     * @return bool Whether an experience record was written.
     */
    public function mirror_course(int $courseid): bool {
        $legacy = $this->collect_legacy_config($courseid);
        if (!$legacy->haslegacy) {
            return false;
        }

        $existing = $this->repository->get_course_experience($courseid, self::COMPONENT);
        $sortorder = $existing ? (int)$existing->sortorder : 10;

        $this->repository->save_course_experience($courseid, self::COMPONENT, $legacy->config,
            $legacy->enabled, $sortorder, self::SCHEMA, false);

        return true;
    }

    /**
     * Returns a merged Learningmap experience config for the course.
     *
     * Existing experience config wins. Legacy config is mirrored on demand when no record exists yet.
     *
     * @param int $courseid
     * @return \stdClass|null
     */
    public function get_course_config(int $courseid): ?\stdClass {
        $record = $this->repository->get_course_experience($courseid, self::COMPONENT);
        if (!$record) {
            $this->mirror_course($courseid);
            $record = $this->repository->get_course_experience($courseid, self::COMPONENT);
        }

        return $record ? $this->repository->decode_config($record) : null;
    }

    /**
     * Resolves the configured main Learningmap CM for the current user.
     *
     * @param \course_modinfo $modinfo
     * @param \stdClass|null $config
     * @return \cm_info|null
     */
    public static function resolve_main_map_cm(\course_modinfo $modinfo, ?\stdClass $config): ?\cm_info {
        return self::resolve_map_cm($modinfo, (int)($config->mainmapcmid ?? 0));
    }

    /**
     * Resolves a configured section Learningmap CM for the current user.
     *
     * @param \course_modinfo $modinfo
     * @param \stdClass|null $config
     * @param int $sectionid
     * @return \cm_info|null
     */
    public static function resolve_section_map_cm(\course_modinfo $modinfo, ?\stdClass $config,
            int $sectionid): ?\cm_info {
        if (empty($config->sectionmapsenabled) || empty($config->sectionmaps) || !$sectionid) {
            return null;
        }

        $sectionmaps = (array)$config->sectionmaps;
        return self::resolve_map_cm($modinfo, (int)($sectionmaps[$sectionid] ?? 0));
    }

    /**
     * Returns all visible Learningmap CMs keyed by cmid.
     *
     * @param \course_modinfo $modinfo
     * @return \cm_info[]
     */
    public static function get_visible_map_cms(\course_modinfo $modinfo): array {
        $cms = [];
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->modname === 'learningmap' && $cm->uservisible && !empty($cm->url)) {
                $cms[(int)$cm->id] = $cm;
            }
        }

        return $cms;
    }

    /**
     * Returns whether the config contains at least one visible map CM.
     *
     * @param \course_modinfo $modinfo
     * @param \stdClass|null $config
     * @return bool
     */
    public static function has_visible_map(\course_modinfo $modinfo, ?\stdClass $config): bool {
        if (!$config) {
            return false;
        }
        if (self::resolve_main_map_cm($modinfo, $config)) {
            return true;
        }
        if (empty($config->sectionmapsenabled) || empty($config->sectionmaps)) {
            return false;
        }
        foreach ((array)$config->sectionmaps as $cmid) {
            if (self::resolve_map_cm($modinfo, (int)$cmid)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Collects legacy settings and builds the shared Learningmap config object.
     *
     * @param int $courseid
     * @return \stdClass
     */
    public function collect_legacy_config(int $courseid): \stdClass {
        global $DB;

        $courseoptions = $this->get_legacy_course_options($courseid);
        $sectionmaps = $this->get_legacy_section_maps($courseid);

        $mainmapcmid = (int)($courseoptions['mainlearningmap'] ?? 0);
        $sectionmapsenabled = array_key_exists('enablesectionmaps', $courseoptions) ?
            (bool)(int)$courseoptions['enablesectionmaps'] : true;
        $avatarenabled = array_key_exists('enableavatar', $courseoptions) ?
            (bool)(int)$courseoptions['enableavatar'] : true;

        $validmapids = $this->get_valid_learningmap_cmids($courseid);
        $usablemainmap = $mainmapcmid && isset($validmapids[$mainmapcmid]);
        $usablesectionmap = false;
        foreach ($sectionmaps as $cmid) {
            if ($sectionmapsenabled && !empty($validmapids[(int)$cmid])) {
                $usablesectionmap = true;
                break;
            }
        }

        $haslegacy = $mainmapcmid > 0 || !empty($sectionmaps) ||
            array_key_exists('mainlearningmap', $courseoptions) ||
            array_key_exists('enablesectionmaps', $courseoptions) ||
            array_key_exists('enableavatar', $courseoptions);

        return (object)[
            'haslegacy' => $haslegacy,
            'enabled' => $usablemainmap || $usablesectionmap,
            'config' => (object)[
                'mainmapcmid' => $mainmapcmid,
                'sectionmaps' => (object)$sectionmaps,
                'sectionmapsenabled' => $sectionmapsenabled,
                'avatarenabled' => $avatarenabled,
                'fullscreenenabled' => true,
                'legacyformatoptions' => (object)[
                    'mainlearningmap' => $mainmapcmid,
                    'enablesectionmaps' => $sectionmapsenabled,
                    'enableavatar' => $avatarenabled,
                ],
            ],
        ];
    }

    /**
     * Resolves a visible Learningmap CM for the current user.
     *
     * @param \course_modinfo $modinfo
     * @param int $cmid
     * @return \cm_info|null
     */
    private static function resolve_map_cm(\course_modinfo $modinfo, int $cmid): ?\cm_info {
        if (!$cmid) {
            return null;
        }

        try {
            $cm = $modinfo->get_cm($cmid);
        } catch (\Throwable $exception) {
            return null;
        }

        if ($cm->modname !== 'learningmap' || !$cm->uservisible || empty($cm->url)) {
            return null;
        }

        return $cm;
    }

    /**
     * Reads course-level legacy format options.
     *
     * @param int $courseid
     * @return array
     */
    private function get_legacy_course_options(int $courseid): array {
        global $DB;

        $records = $DB->get_records_select('course_format_options',
            'courseid = :courseid AND format = :format AND (sectionid = 0 OR sectionid IS NULL)
             AND name IN (:mainmap, :sectionmaps, :avatar)',
            [
                'courseid' => $courseid,
                'format' => 'selfstudy',
                'mainmap' => 'mainlearningmap',
                'sectionmaps' => 'enablesectionmaps',
                'avatar' => 'enableavatar',
            ],
            '',
            'name,value'
        );

        $options = [];
        foreach ($records as $record) {
            $options[(string)$record->name] = $record->value;
        }

        return $options;
    }

    /**
     * Reads section-level legacy map assignments.
     *
     * @param int $courseid
     * @return array sectionid => cmid
     */
    private function get_legacy_section_maps(int $courseid): array {
        global $DB;

        $records = $DB->get_records('course_format_options', [
            'courseid' => $courseid,
            'format' => 'selfstudy',
            'name' => 'sectionmap',
        ], '', 'sectionid,value');

        $sectionmaps = [];
        foreach ($records as $record) {
            $sectionid = (int)($record->sectionid ?? 0);
            $cmid = (int)($record->value ?? 0);
            if ($sectionid > 0 && $cmid > 0) {
                $sectionmaps[$sectionid] = $cmid;
            }
        }

        return $sectionmaps;
    }

    /**
     * Returns valid visible Learningmap CM ids for course-level migration decisions.
     *
     * @param int $courseid
     * @return array
     */
    private function get_valid_learningmap_cmids(int $courseid): array {
        global $DB;

        $records = $DB->get_records_sql(
            "SELECT cm.id
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid
                AND m.name = :modname
                AND cm.visible = 1
                AND cm.deletioninprogress = 0",
            ['courseid' => $courseid, 'modname' => 'learningmap']
        );

        return array_fill_keys(array_map('intval', array_keys($records)), true);
    }
}
