<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for runtime path reads.
 *
 * @package    format_selfstudy
 * @category   test
 */
class format_selfstudy_path_runtime_test extends advanced_testcase {

    public function test_runtime_prefers_persisted_snapshot(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'selfstudy']);

        $repository = new \format_selfstudy\local\path_repository();
        $pathid = $repository->create_path((int)$course->id, [
            'name' => 'Persisted runtime path',
            'enabled' => 1,
            'userid' => 0,
        ]);

        $snapshotrepository = new \format_selfstudy\local\path_snapshot_repository();
        $snapshotrepository->save_snapshot($pathid, (int)$course->id, 0, (object)[
            'schema' => 1,
            'json' => json_encode([
                'schema' => 1,
                'path' => ['id' => $pathid, 'courseid' => (int)$course->id, 'userid' => 0],
                'nodes' => [['key' => 'stored-node', 'type' => 'milestone']],
                'root' => ['stored-node'],
                'rules' => [],
            ]),
            'sourcehash' => str_repeat('d', 64),
        ]);

        $runtime = new \format_selfstudy\local\path_runtime($repository, $snapshotrepository);
        $snapshot = $runtime->get_runtime_snapshot($course, $pathid);

        $this->assertNotNull($snapshot);
        $this->assertSame(\format_selfstudy\local\path_runtime::SOURCE_SNAPSHOT, $snapshot->source);
        $this->assertTrue($snapshot->persisted);
        $this->assertSame($pathid, $snapshot->pathid);
        $this->assertSame(str_repeat('d', 64), $snapshot->sourcehash);
        $this->assertSame(['stored-node'], $snapshot->decoded['root']);
        $this->assertSame(['stored-node'], $runtime->get_decoded_runtime_snapshot($course, $pathid)['root']);
    }

    public function test_runtime_falls_back_to_current_path_items_without_persisting(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy', 'numsections' => 1]);
        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Fallback station'],
            ['section' => 1]);

        $repository = new \format_selfstudy\local\path_repository();
        $pathid = $repository->create_path((int)$course->id, [
            'name' => 'Fallback runtime path',
            'enabled' => 0,
            'userid' => 0,
        ]);
        $repository->replace_path_items($pathid, [
            [
                'itemtype' => \format_selfstudy\local\path_repository::ITEM_MILESTONE,
                'title' => 'Fallback start',
                'configdata' => ['milestonekey' => 'fallback-start'],
            ],
            [
                'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
                'cmid' => (int)$page->cmid,
            ],
        ]);

        $snapshotrepository = new \format_selfstudy\local\path_snapshot_repository();
        $runtime = new \format_selfstudy\local\path_runtime($repository, $snapshotrepository);
        $snapshot = $runtime->get_runtime_snapshot($course, $pathid);

        $this->assertNotNull($snapshot);
        $this->assertSame(\format_selfstudy\local\path_runtime::SOURCE_FALLBACK, $snapshot->source);
        $this->assertFalse($snapshot->persisted);
        $this->assertSame(['milestone-fallback-start'], $snapshot->decoded['root']);
        $this->assertNull($snapshotrepository->get_snapshot($pathid));
    }

    public function test_runtime_returns_null_for_path_from_another_course(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'selfstudy']);
        $othercourse = $this->getDataGenerator()->create_course(['format' => 'selfstudy']);

        $repository = new \format_selfstudy\local\path_repository();
        $pathid = $repository->create_path((int)$course->id, [
            'name' => 'Wrong course path',
            'enabled' => 0,
            'userid' => 0,
        ]);

        $runtime = new \format_selfstudy\local\path_runtime($repository);

        $this->assertNull($runtime->get_runtime_snapshot($othercourse, $pathid));
        $this->assertNull($runtime->get_decoded_runtime_snapshot($othercourse, $pathid));
    }
}
