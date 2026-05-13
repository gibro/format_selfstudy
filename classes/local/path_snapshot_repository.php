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

    /** @var string Snapshot revision table. */
    private const TABLE_REVISIONS = 'format_selfstudy_revisions';

    /** @var string Published revision status. */
    public const STATUS_PUBLISHED = 'published';

    /** @var string Archived revision status. */
    public const STATUS_ARCHIVED = 'archived';

    /**
     * Creates or replaces the published snapshot for a path.
     *
     * @param int $pathid
     * @param int $courseid
     * @param int $userid
     * @param \stdClass $snapshot
     */
    public function save_snapshot(int $pathid, int $courseid, int $userid, \stdClass $snapshot): void {
        $this->save_revision($pathid, $courseid, $userid, $snapshot);
    }

    /**
     * Creates a new published revision and archives the previous active revision.
     *
     * The legacy snapshot table is still updated as the active compatibility
     * pointer so older installs and backup data remain readable.
     *
     * @param int $pathid
     * @param int $courseid
     * @param int $userid
     * @param \stdClass $snapshot
     * @param int $publishedby
     * @param int|null $timepublished
     * @return \stdClass
     */
    public function save_revision(int $pathid, int $courseid, int $userid, \stdClass $snapshot,
            int $publishedby = 0, ?int $timepublished = null): \stdClass {
        global $DB;

        [$schema, $json, $sourcehash] = $this->normalise_snapshot($snapshot);
        $now = $timepublished ?? time();
        $revision = $this->get_next_revision_number($pathid);

        $transaction = $DB->start_delegated_transaction();
        $DB->set_field(self::TABLE_REVISIONS, 'status', self::STATUS_ARCHIVED, [
            'pathid' => $pathid,
            'active' => 1,
        ]);
        $DB->set_field(self::TABLE_REVISIONS, 'active', 0, [
            'pathid' => $pathid,
            'active' => 1,
        ]);

        $record = (object)[
            'pathid' => $pathid,
            'courseid' => $courseid,
            'userid' => $userid,
            'revision' => $revision,
            'schema' => $schema,
            'snapshotjson' => $json,
            'sourcehash' => $sourcehash,
            'publishedby' => $publishedby,
            'timepublished' => $now,
            'status' => self::STATUS_PUBLISHED,
            'active' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = (int)$DB->insert_record(self::TABLE_REVISIONS, $record);

        $this->save_active_snapshot_record($pathid, $courseid, $userid, $schema, $json, $sourcehash, $now);
        $transaction->allow_commit();

        return $record;
    }

    /**
     * Returns the active published revision for a path.
     *
     * @param int $pathid
     * @return \stdClass|null
     */
    public function get_active_revision(int $pathid): ?\stdClass {
        global $DB;

        return $DB->get_record(self::TABLE_REVISIONS, [
            'pathid' => $pathid,
            'active' => 1,
            'status' => self::STATUS_PUBLISHED,
        ], '*', IGNORE_MISSING) ?: null;
    }

    /**
     * Returns all revisions for a path, newest first.
     *
     * @param int $pathid
     * @return \stdClass[]
     */
    public function get_revisions(int $pathid): array {
        global $DB;

        return array_values($DB->get_records(self::TABLE_REVISIONS, ['pathid' => $pathid],
            'revision DESC, id DESC'));
    }

    /**
     * Returns the next revision number for a path.
     *
     * @param int $pathid
     * @return int
     */
    private function get_next_revision_number(int $pathid): int {
        global $DB;

        $current = $DB->get_field_sql('SELECT MAX(revision)
                                          FROM {' . self::TABLE_REVISIONS . '}
                                         WHERE pathid = :pathid', ['pathid' => $pathid]);
        return (int)$current + 1;
    }

    /**
     * Validates and normalises snapshot data.
     *
     * @param \stdClass $snapshot
     * @return array
     */
    private function normalise_snapshot(\stdClass $snapshot): array {
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

        return [$schema, $json, $sourcehash];
    }

    /**
     * Updates the compatibility snapshot row for the active revision.
     *
     * @param int $pathid
     * @param int $courseid
     * @param int $userid
     * @param int $schema
     * @param string $json
     * @param string $sourcehash
     * @param int $now
     */
    private function save_active_snapshot_record(int $pathid, int $courseid, int $userid, int $schema, string $json,
            string $sourcehash, int $now): void {
        global $DB;

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

        $revision = $this->get_active_revision($pathid);
        if ($revision) {
            return $revision;
        }

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

        $DB->delete_records(self::TABLE_REVISIONS, ['pathid' => $pathid]);
        $DB->delete_records(self::TABLE_SNAPSHOTS, ['pathid' => $pathid]);
    }
}
