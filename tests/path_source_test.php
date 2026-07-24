<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for the availability source path model (multiple learning paths).
 *
 * @package    format_selfstudy
 * @category   test
 */
class format_selfstudy_path_source_test extends advanced_testcase {

    public function test_first_published_path_becomes_source_and_siblings_stay_enabled(): void {
        $this->resetAfterTest();
        [$course, $repository, $sync, $publisher, $firstpathid, $secondpathid] = $this->create_two_course_paths();

        $firstresult = $publisher->publish_course_path($course, $firstpathid);

        $this->assertTrue($firstresult->published);
        $this->assertTrue($firstresult->issource);
        $this->assertSame(1, $sync->synccalls);
        $this->assertSame(1, (int)$repository->get_path($firstpathid)->issource);
        $this->assertSame(1, (int)$repository->get_path($firstpathid)->enabled);

        $secondresult = $publisher->publish_course_path($course, $secondpathid);

        $this->assertTrue($secondresult->published);
        $this->assertFalse($secondresult->issource);
        $this->assertSame(1, $sync->synccalls, 'Publishing a non-source path must not write availability.');
        $this->assertSame(0, $secondresult->written);
        $this->assertNotEmpty($secondresult->warnings);

        // Both course paths stay published side by side.
        $this->assertSame(1, (int)$repository->get_path($firstpathid)->enabled);
        $this->assertSame(1, (int)$repository->get_path($secondpathid)->enabled);
        $this->assertSame(1, (int)$repository->get_path($firstpathid)->issource);
        $this->assertSame(0, (int)$repository->get_path($secondpathid)->issource);
        $this->assertSame($firstpathid, (int)$repository->get_source_path((int)$course->id)->id);
    }

    public function test_set_source_path_moves_the_single_source_marker(): void {
        $this->resetAfterTest();
        [$course, $repository, $sync, $publisher, $firstpathid, $secondpathid] = $this->create_two_course_paths();
        $publisher->publish_course_path($course, $firstpathid);
        $publisher->publish_course_path($course, $secondpathid);

        $repository->set_source_path((int)$course->id, $secondpathid);

        $this->assertSame(0, (int)$repository->get_path($firstpathid)->issource);
        $this->assertSame(1, (int)$repository->get_path($secondpathid)->issource);
        $this->assertSame($secondpathid, (int)$repository->get_source_path((int)$course->id)->id);
    }

    public function test_set_source_path_rejects_draft_paths(): void {
        $this->resetAfterTest();
        [$course, $repository] = $this->create_two_course_paths();
        $draftpathid = $repository->create_path((int)$course->id, [
            'name' => 'Draft path',
            'enabled' => 0,
            'userid' => 0,
        ]);

        $this->expectException(\coding_exception::class);
        $repository->set_source_path((int)$course->id, $draftpathid);
    }

    public function test_set_course_source_path_resyncs_only_on_change(): void {
        $this->resetAfterTest();
        [$course, $repository, $sync, $publisher, $firstpathid, $secondpathid] = $this->create_two_course_paths();
        $publisher->publish_course_path($course, $firstpathid);
        $publisher->publish_course_path($course, $secondpathid);
        $callsbefore = $sync->synccalls;

        $unchanged = $publisher->set_course_source_path($course, $firstpathid);

        $this->assertFalse($unchanged->changed);
        $this->assertSame($callsbefore, $sync->synccalls);

        $changed = $publisher->set_course_source_path($course, $secondpathid);

        $this->assertTrue($changed->changed);
        $this->assertSame($callsbefore + 1, $sync->synccalls);
        $this->assertSame($secondpathid, (int)$repository->get_source_path((int)$course->id)->id);
        $this->assertSame(7, $changed->written);
    }

    /**
     * Creates a selfstudy course with two course-level draft paths.
     *
     * @return array [$course, $repository, $sync, $publisher, $firstpathid, $secondpathid]
     */
    private function create_two_course_paths(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy', 'numsections' => 1]);
        $firstpage = $generator->create_module('page', ['course' => $course->id, 'name' => 'Station A'],
            ['section' => 1]);
        $secondpage = $generator->create_module('page', ['course' => $course->id, 'name' => 'Station B'],
            ['section' => 1]);

        $repository = new \format_selfstudy\local\path_repository();
        $items = static function(int $cmid): array {
            return [
                [
                    'itemtype' => \format_selfstudy\local\path_repository::ITEM_MILESTONE,
                    'title' => 'Start',
                    'configdata' => ['milestonekey' => 'start'],
                ],
                [
                    'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
                    'cmid' => $cmid,
                ],
            ];
        };

        $firstpathid = $repository->create_path((int)$course->id, [
            'name' => 'Beginner path',
            'enabled' => 0,
            'userid' => 0,
        ]);
        $repository->replace_path_items($firstpathid, $items((int)$firstpage->cmid));

        $secondpathid = $repository->create_path((int)$course->id, [
            'name' => 'Advanced path',
            'enabled' => 0,
            'userid' => 0,
        ]);
        $repository->replace_path_items($secondpathid, $items((int)$secondpage->cmid));

        $sync = new format_selfstudy_path_source_sync_stub($repository);
        $publisher = new \format_selfstudy\local\path_publish_service(
            $repository,
            $sync,
            new \format_selfstudy\local\path_snapshot_builder(),
            new \format_selfstudy\local\path_snapshot_repository()
        );

        return [$course, $repository, $sync, $publisher, $firstpathid, $secondpathid];
    }
}

/**
 * Counting test double for path_sync.
 */
class format_selfstudy_path_source_sync_stub extends \format_selfstudy\local\path_sync {

    /** @var int */
    public $synccalls = 0;

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
     * Reports nothing to repair.
     *
     * @param \stdClass $course
     * @param int $pathid
     * @return \stdClass
     */
    public function repair_path_issues(\stdClass $course, int $pathid): \stdClass {
        return (object)[
            'fixed' => 0,
            'skipped' => 0,
            'completionfixed' => 0,
            'availabilityfixed' => 0,
            'errors' => [],
        ];
    }

    /**
     * Counts sync calls and returns a fixed result.
     *
     * @param \stdClass $course
     * @param int $pathid
     * @return \stdClass
     */
    public function sync(\stdClass $course, int $pathid): \stdClass {
        $this->synccalls++;

        return (object)[
            'written' => 7,
            'skipped' => 1,
            'errors' => [],
        ];
    }
}
