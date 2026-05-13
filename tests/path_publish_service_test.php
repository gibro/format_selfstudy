<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for publishing learning paths.
 *
 * @package    format_selfstudy
 * @category   test
 */
class format_selfstudy_path_publish_service_test extends advanced_testcase {

    public function test_publish_saves_runtime_snapshot_after_successful_sync(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy', 'numsections' => 1]);
        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Station'], ['section' => 1]);

        $repository = new \format_selfstudy\local\path_repository();
        $pathid = $repository->create_path((int)$course->id, [
            'name' => 'Publish snapshot path',
            'enabled' => 0,
            'userid' => 0,
        ]);
        $repository->replace_path_items($pathid, [
            [
                'itemtype' => \format_selfstudy\local\path_repository::ITEM_MILESTONE,
                'title' => 'Start',
                'configdata' => ['milestonekey' => 'start'],
            ],
            [
                'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
                'cmid' => (int)$page->cmid,
            ],
        ]);

        $snapshots = new \format_selfstudy\local\path_snapshot_repository();
        $publisher = new \format_selfstudy\local\path_publish_service(
            $repository,
            new format_selfstudy_path_publish_service_sync_stub($repository),
            new \format_selfstudy\local\path_snapshot_builder(),
            $snapshots
        );

        $result = $publisher->publish_course_path($course, $pathid);

        $this->assertTrue($result->published);
        $this->assertTrue($result->snapshot);
        $this->assertSame(1, (int)$result->revision);
        $this->assertGreaterThan(0, (int)$result->timepublished);
        $this->assertSame(7, $result->written);
        $this->assertSame(1, $result->skipped);
        $this->assertSame(1, (int)$repository->get_path($pathid)->enabled);

        $stored = $snapshots->get_snapshot($pathid);
        $this->assertNotNull($stored);
        $this->assertSame($pathid, (int)$stored->pathid);
        $this->assertSame((int)$course->id, (int)$stored->courseid);
        $this->assertSame(64, strlen($stored->sourcehash));
        $this->assertSame(1, (int)$stored->revision);
        $this->assertSame(\format_selfstudy\local\path_snapshot_repository::STATUS_PUBLISHED, $stored->status);
        $this->assertSame(['milestone-start'], $snapshots->get_decoded_snapshot($pathid)['root']);
    }

    public function test_publish_does_not_save_snapshot_when_sync_fails(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy', 'numsections' => 1]);
        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Station'], ['section' => 1]);

        $repository = new \format_selfstudy\local\path_repository();
        $pathid = $repository->create_path((int)$course->id, [
            'name' => 'Failed publish path',
            'enabled' => 0,
            'userid' => 0,
        ]);
        $repository->replace_path_items($pathid, [
            [
                'itemtype' => \format_selfstudy\local\path_repository::ITEM_MILESTONE,
                'title' => 'Start',
                'configdata' => ['milestonekey' => 'start'],
            ],
            [
                'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
                'cmid' => (int)$page->cmid,
            ],
        ]);

        $snapshots = new \format_selfstudy\local\path_snapshot_repository();
        $publisher = new \format_selfstudy\local\path_publish_service(
            $repository,
            new format_selfstudy_path_publish_service_sync_stub($repository, ['sync failed']),
            new \format_selfstudy\local\path_snapshot_builder(),
            $snapshots
        );

        $result = $publisher->publish_course_path($course, $pathid);

        $this->assertFalse($result->published);
        $this->assertFalse($result->snapshot);
        $this->assertSame(0, (int)$result->revision);
        $this->assertSame(['sync failed'], $result->errors);
        $this->assertNull($snapshots->get_snapshot($pathid));
        $this->assertSame([], $snapshots->get_revisions($pathid));
    }
}

/**
 * Test double for path_sync.
 */
class format_selfstudy_path_publish_service_sync_stub extends \format_selfstudy\local\path_sync {

    /** @var string[] */
    private $syncerrors;

    /**
     * Constructor.
     *
     * @param \format_selfstudy\local\path_repository $repository
     * @param string[] $syncerrors
     */
    public function __construct(\format_selfstudy\local\path_repository $repository, array $syncerrors = []) {
        parent::__construct($repository);
        $this->syncerrors = $syncerrors;
    }

    /**
     * Returns an empty diagnosis.
     *
     * @param \stdClass $course
     * @param int $pathid
     * @return \stdClass
     */
    public function diagnose(\stdClass $course, int $pathid): \stdClass {
        return (object)[
            'invalidcompletionavailability' => [],
            'invalidstructureavailability' => [],
            'untranslatable' => [],
        ];
    }

    /**
     * Returns a configured sync result.
     *
     * @param \stdClass $course
     * @param int $pathid
     * @return \stdClass
     */
    public function sync(\stdClass $course, int $pathid): \stdClass {
        return (object)[
            'written' => $this->syncerrors ? 0 : 7,
            'skipped' => $this->syncerrors ? 0 : 1,
            'errors' => $this->syncerrors,
        ];
    }
}
