<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read layer for published runtime path data.
 */
class path_runtime {

    /** @var string Runtime data came from the published snapshot table. */
    public const SOURCE_SNAPSHOT = 'snapshot';

    /** @var string Runtime data was built from draft path items as a compatibility fallback. */
    public const SOURCE_FALLBACK = 'fallback';

    /** @var path_repository */
    private $repository;

    /** @var path_snapshot_repository */
    private $snapshotrepository;

    /** @var path_snapshot_builder */
    private $snapshotbuilder;

    /**
     * Constructor.
     *
     * @param path_repository|null $repository
     * @param path_snapshot_repository|null $snapshotrepository
     * @param path_snapshot_builder|null $snapshotbuilder
     */
    public function __construct(?path_repository $repository = null,
            ?path_snapshot_repository $snapshotrepository = null, ?path_snapshot_builder $snapshotbuilder = null) {
        $this->repository = $repository ?? new path_repository();
        $this->snapshotrepository = $snapshotrepository ?? new path_snapshot_repository();
        $this->snapshotbuilder = $snapshotbuilder ?? new path_snapshot_builder();
    }

    /**
     * Returns runtime snapshot data for a path.
     *
     * Published snapshots are preferred. If no snapshot exists yet, the method
     * builds a transient compatibility snapshot from current path items.
     *
     * @param \stdClass $course
     * @param int $pathid
     * @return \stdClass|null
     */
    public function get_runtime_snapshot(\stdClass $course, int $pathid): ?\stdClass {
        $stored = $this->snapshotrepository->get_snapshot($pathid);
        if ($stored) {
            $decoded = $this->snapshotrepository->get_decoded_snapshot($pathid);

            return (object)[
                'source' => self::SOURCE_SNAPSHOT,
                'persisted' => true,
                'pathid' => (int)$stored->pathid,
                'courseid' => (int)$stored->courseid,
                'userid' => (int)$stored->userid,
                'schema' => (int)$stored->schema,
                'sourcehash' => (string)$stored->sourcehash,
                'json' => (string)$stored->snapshotjson,
                'decoded' => $decoded,
                'revision' => (int)($stored->revision ?? 0),
                'publishedby' => (int)($stored->publishedby ?? 0),
                'timepublished' => (int)($stored->timepublished ?? 0),
                'status' => (string)($stored->status ?? ''),
            ];
        }

        $path = $this->repository->get_path_with_items($pathid);
        if (!$path || (int)$path->courseid !== (int)$course->id) {
            return null;
        }

        $snapshot = $this->snapshotbuilder->build_snapshot($course, $path);
        $decoded = json_decode((string)$snapshot->json, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \coding_exception('Built runtime snapshot JSON is invalid.');
        }

        return (object)[
            'source' => self::SOURCE_FALLBACK,
            'persisted' => false,
            'pathid' => $pathid,
            'courseid' => (int)$course->id,
            'userid' => (int)($path->userid ?? 0),
            'schema' => (int)$snapshot->schema,
            'sourcehash' => (string)$snapshot->sourcehash,
            'json' => (string)$snapshot->json,
            'decoded' => $decoded,
        ];
    }

    /**
     * Returns only decoded runtime snapshot data for a path.
     *
     * @param \stdClass $course
     * @param int $pathid
     * @return array|null
     */
    public function get_decoded_runtime_snapshot(\stdClass $course, int $pathid): ?array {
        $runtime = $this->get_runtime_snapshot($course, $pathid);
        return $runtime ? $runtime->decoded : null;
    }
}
