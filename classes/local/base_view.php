<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds the canonical learner-facing base view model for a selfstudy path.
 */
class base_view {

    /**
     * Returns the active base view for one learner.
     *
     * The active course-level published revision is preferred. Personal learner
     * paths and enabled paths remain compatibility fallbacks for older courses
     * without a published runtime revision.
     *
     * @param \stdClass $course
     * @param int $userid
     * @return \stdClass
     */
    public static function create(\stdClass $course, int $userid): \stdClass {
        $repository = new path_repository();
        $runtime = new path_runtime($repository);
        $path = self::get_active_published_course_path((int)$course->id) ??
            self::get_selected_path($repository, (int)$course->id, $userid) ??
            self::get_first_enabled_course_path($repository, (int)$course->id);

        $snapshot = $path ? $runtime->get_runtime_snapshot($course, (int)$path->id) : null;
        $progress = $path ? path_progress::calculate($course, (int)$path->id, $userid) : null;
        $outline = $path ? path_progress::outline($course, (int)$path->id, $userid) : [];

        return (object)[
            'path' => $path,
            'progress' => $progress,
            'outline' => $outline,
            'runtime' => $snapshot,
            'source' => $snapshot->source ?? '',
            'revision' => (int)($snapshot->revision ?? 0),
            'timepublished' => (int)($snapshot->timepublished ?? 0),
            'publishedby' => (int)($snapshot->publishedby ?? 0),
        ];
    }

    /**
     * Returns the learner-selected path when it is usable.
     *
     * @param path_repository $repository
     * @param int $courseid
     * @param int $userid
     * @return \stdClass|null
     */
    private static function get_selected_path(path_repository $repository, int $courseid, int $userid): ?\stdClass {
        $path = $repository->get_active_path($courseid, $userid);
        if (!$path || empty($path->enabled) || (int)$path->courseid !== $courseid) {
            return null;
        }

        $owner = (int)($path->userid ?? 0);
        if ($owner !== 0 && $owner !== $userid) {
            return null;
        }

        return $path;
    }

    /**
     * Returns the enabled course path with an active published revision.
     *
     * @param int $courseid
     * @return \stdClass|null
     */
    private static function get_active_published_course_path(int $courseid): ?\stdClass {
        global $DB;

        $sql = "SELECT p.*
                  FROM {format_selfstudy_paths} p
                  JOIN {format_selfstudy_revisions} r ON r.pathid = p.id
                 WHERE p.courseid = :courseid
                   AND p.userid = 0
                   AND p.enabled = 1
                   AND r.active = 1
                   AND r.status = :status
              ORDER BY p.sortorder ASC, p.id ASC";

        return $DB->get_record_sql($sql, [
            'courseid' => $courseid,
            'status' => path_snapshot_repository::STATUS_PUBLISHED,
        ], IGNORE_MULTIPLE) ?: null;
    }

    /**
     * Returns the first enabled course path for old courses without revisions.
     *
     * @param path_repository $repository
     * @param int $courseid
     * @return \stdClass|null
     */
    private static function get_first_enabled_course_path(path_repository $repository, int $courseid): ?\stdClass {
        $paths = $repository->get_paths($courseid, true, 0);
        return $paths[0] ?? null;
    }
}
