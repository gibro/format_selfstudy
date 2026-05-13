<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for published runtime snapshot persistence.
 *
 * @package    format_selfstudy
 * @category   test
 */
class format_selfstudy_path_snapshot_repository_test extends advanced_testcase {

    public function test_save_load_decode_overwrite_and_delete_snapshot(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'selfstudy']);

        $pathrepository = new \format_selfstudy\local\path_repository();
        $pathid = $pathrepository->create_path((int)$course->id, [
            'name' => 'Snapshot path',
            'enabled' => 1,
            'userid' => 0,
        ]);
        $repository = new \format_selfstudy\local\path_snapshot_repository();

        $repository->save_snapshot($pathid, (int)$course->id, 0, (object)[
            'schema' => 1,
            'json' => json_encode([
                'schema' => 1,
                'root' => ['milestone-1'],
                'nodes' => [
                    ['key' => 'milestone-1', 'type' => 'milestone'],
                ],
                'rules' => [],
            ]),
            'sourcehash' => str_repeat('a', 64),
        ]);

        $stored = $repository->get_snapshot($pathid);
        $this->assertNotNull($stored);
        $this->assertSame($pathid, (int)$stored->pathid);
        $this->assertSame((int)$course->id, (int)$stored->courseid);
        $this->assertSame(0, (int)$stored->userid);
        $this->assertSame(1, (int)$stored->schema);
        $this->assertSame(str_repeat('a', 64), $stored->sourcehash);

        $decoded = $repository->get_decoded_snapshot($pathid);
        $this->assertSame(1, $decoded['schema']);
        $this->assertSame(['milestone-1'], $decoded['root']);

        $repository->save_snapshot($pathid, (int)$course->id, 0, (object)[
            'schema' => 1,
            'json' => json_encode([
                'schema' => 1,
                'root' => ['milestone-2'],
                'nodes' => [
                    ['key' => 'milestone-2', 'type' => 'milestone'],
                ],
                'rules' => [],
            ]),
            'sourcehash' => str_repeat('b', 64),
        ]);

        $this->assertSame(str_repeat('b', 64), $repository->get_snapshot($pathid)->sourcehash);
        $this->assertSame(['milestone-2'], $repository->get_decoded_snapshot($pathid)['root']);
        $this->assertSame(2, (int)$repository->get_active_revision($pathid)->revision);
        $this->assertCount(2, $repository->get_revisions($pathid));
        $this->assertSame([2, 1], array_map(static function(stdClass $revision): int {
            return (int)$revision->revision;
        }, $repository->get_revisions($pathid)));

        $repository->delete_snapshot($pathid);
        $this->assertNull($repository->get_snapshot($pathid));
        $this->assertNull($repository->get_decoded_snapshot($pathid));
        $this->assertNull($repository->get_active_revision($pathid));
        $this->assertSame([], $repository->get_revisions($pathid));
    }

    public function test_save_revision_archives_previous_revision_and_keeps_one_active(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'selfstudy']);

        $pathrepository = new \format_selfstudy\local\path_repository();
        $pathid = $pathrepository->create_path((int)$course->id, [
            'name' => 'Revision path',
            'enabled' => 1,
            'userid' => 0,
        ]);
        $repository = new \format_selfstudy\local\path_snapshot_repository();

        $first = $repository->save_revision($pathid, (int)$course->id, 0, (object)[
            'schema' => 1,
            'json' => json_encode(['schema' => 1, 'root' => ['first'], 'nodes' => [], 'rules' => []]),
            'sourcehash' => str_repeat('d', 64),
        ], 2, 100);
        $second = $repository->save_revision($pathid, (int)$course->id, 0, (object)[
            'schema' => 1,
            'json' => json_encode(['schema' => 1, 'root' => ['second'], 'nodes' => [], 'rules' => []]),
            'sourcehash' => str_repeat('e', 64),
        ], 3, 200);

        $this->assertSame(1, (int)$first->revision);
        $this->assertSame(2, (int)$second->revision);

        $active = $repository->get_active_revision($pathid);
        $this->assertNotNull($active);
        $this->assertSame(2, (int)$active->revision);
        $this->assertSame(3, (int)$active->publishedby);
        $this->assertSame(200, (int)$active->timepublished);
        $this->assertSame(\format_selfstudy\local\path_snapshot_repository::STATUS_PUBLISHED, $active->status);
        $this->assertSame(['second'], $repository->get_decoded_snapshot($pathid)['root']);

        $revisions = $repository->get_revisions($pathid);
        $this->assertCount(2, $revisions);
        $this->assertSame(\format_selfstudy\local\path_snapshot_repository::STATUS_ARCHIVED, $revisions[1]->status);
        $this->assertSame(0, (int)$revisions[1]->active);
    }

    public function test_delete_path_removes_dependent_snapshot(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'selfstudy']);

        $pathrepository = new \format_selfstudy\local\path_repository();
        $pathid = $pathrepository->create_path((int)$course->id, [
            'name' => 'Deletable snapshot path',
            'enabled' => 1,
            'userid' => 0,
        ]);
        $snapshotrepository = new \format_selfstudy\local\path_snapshot_repository();
        $snapshotrepository->save_snapshot($pathid, (int)$course->id, 0, (object)[
            'schema' => 1,
            'json' => json_encode(['schema' => 1, 'root' => [], 'nodes' => [], 'rules' => []]),
            'sourcehash' => str_repeat('c', 64),
        ]);

        $pathrepository->delete_path($pathid);

        $this->assertFalse($DB->record_exists('format_selfstudy_paths', ['id' => $pathid]));
        $this->assertFalse($DB->record_exists('format_selfstudy_snapshots', ['pathid' => $pathid]));
        $this->assertFalse($DB->record_exists('format_selfstudy_revisions', ['pathid' => $pathid]));
    }
}
