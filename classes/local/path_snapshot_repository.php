<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Persistence API for published runtime snapshots.
 */
class path_snapshot_repository {

    /** @var string Snapshot table. */
    private const TABLE_SNAPSHOTS = 'format_selfstudy_snapshots';

    /**
     * Creates or replaces the published snapshot for a path.
     *
     * @param int $pathid
     * @param int $courseid
     * @param int $userid
     * @param \stdClass $snapshot
     */
    public function save_snapshot(int $pathid, int $courseid, int $userid, \stdClass $snapshot): void {
        global $DB;

        $schema = (int)($snapshot->schema ?? 0);
        $json = (string)($snapshot->json ?? '');
        $sourcehash = (string)($snapshot->sourcehash ?? '');

        if ($schema < 1) {
            throw new \coding_exception('Snapshot schema must be a positive integer.');
        }
        if ($json === '') {
            throw new \coding_exception('Snapshot JSON must not be empty.');
        }
        if (strlen($sourcehash) !== 64) {
            throw new \coding_exception('Snapshot source hash must be 64 characters.');
        }

        $now = time();
        $existing = $DB->get_record(self::TABLE_SNAPSHOTS, ['pathid' => $pathid], '*', IGNORE_MISSING);

        if ($existing) {
            $existing->courseid = $courseid;
            $existing->userid = $userid;
            $existing->schema = $schema;
            $existing->snapshotjson = $json;
            $existing->sourcehash = $sourcehash;
            $existing->timemodified = $now;
            $DB->update_record(self::TABLE_SNAPSHOTS, $existing);
            return;
        }

        $DB->insert_record(self::TABLE_SNAPSHOTS, (object)[
            'pathid' => $pathid,
            'courseid' => $courseid,
            'userid' => $userid,
            'schema' => $schema,
            'snapshotjson' => $json,
            'sourcehash' => $sourcehash,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Returns the stored snapshot record for a path.
     *
     * @param int $pathid
     * @return \stdClass|null
     */
    public function get_snapshot(int $pathid): ?\stdClass {
        global $DB;

        return $DB->get_record(self::TABLE_SNAPSHOTS, ['pathid' => $pathid], '*', IGNORE_MISSING) ?: null;
    }

    /**
     * Returns the decoded snapshot JSON for a path.
     *
     * @param int $pathid
     * @return array|null
     */
    public function get_decoded_snapshot(int $pathid): ?array {
        $snapshot = $this->get_snapshot($pathid);
        if (!$snapshot) {
            return null;
        }

        $decoded = json_decode((string)$snapshot->snapshotjson, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \coding_exception('Stored snapshot JSON is invalid.');
        }

        return $decoded;
    }

    /**
     * Deletes the stored snapshot for a path.
     *
     * @param int $pathid
     */
    public function delete_snapshot(int $pathid): void {
        global $DB;

        $DB->delete_records(self::TABLE_SNAPSHOTS, ['pathid' => $pathid]);
    }
}
