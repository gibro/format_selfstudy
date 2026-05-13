<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for progress reads through runtime snapshots.
 *
 * @package    format_selfstudy
 * @category   test
 */
class format_selfstudy_path_progress_runtime_test extends advanced_testcase {

    public function test_outline_prefers_persisted_runtime_snapshot_over_current_draft_items(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy', 'numsections' => 1]);
        $snapshotpage = $generator->create_module('page', ['course' => $course->id, 'name' => 'Published station'],
            ['section' => 1]);
        $draftpage = $generator->create_module('page', ['course' => $course->id, 'name' => 'Draft station'],
            ['section' => 1]);
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id);

        $repository = new \format_selfstudy\local\path_repository();
        $pathid = $repository->create_path((int)$course->id, [
            'name' => 'Runtime progress path',
            'enabled' => 1,
            'userid' => 0,
        ]);
        $repository->replace_path_items($pathid, [
            [
                'itemtype' => \format_selfstudy\local\path_repository::ITEM_MILESTONE,
                'title' => 'Published milestone',
                'configdata' => ['milestonekey' => 'published'],
            ],
            [
                'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
                'cmid' => (int)$snapshotpage->cmid,
            ],
        ]);

        $builder = new \format_selfstudy\local\path_snapshot_builder();
        $snapshot = $builder->build_snapshot($course, $repository->get_path_with_items($pathid));
        $snapshotrepository = new \format_selfstudy\local\path_snapshot_repository();
        $snapshotrepository->save_snapshot($pathid, (int)$course->id, 0, $snapshot);

        $repository->replace_path_items($pathid, [
            [
                'itemtype' => \format_selfstudy\local\path_repository::ITEM_MILESTONE,
                'title' => 'Draft milestone',
                'configdata' => ['milestonekey' => 'draft'],
            ],
            [
                'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
                'cmid' => (int)$draftpage->cmid,
            ],
        ]);

        $outline = \format_selfstudy\local\path_progress::outline($course, $pathid, (int)$user->id);
        $titles = array_map(static function(\stdClass $entry): string {
            return $entry->title;
        }, $outline);

        $this->assertContains('Published milestone', $titles);
        $this->assertContains('Published station', $titles);
        $this->assertNotContains('Draft milestone', $titles);
        $this->assertNotContains('Draft station', $titles);
    }

    public function test_calculate_uses_runtime_fallback_when_no_snapshot_exists(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy', 'numsections' => 1]);
        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Fallback station'],
            ['section' => 1]);
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id);

        $repository = new \format_selfstudy\local\path_repository();
        $pathid = $repository->create_path((int)$course->id, [
            'name' => 'Fallback progress path',
            'enabled' => 0,
            'userid' => 0,
        ]);
        $repository->replace_path_items($pathid, [
            [
                'itemtype' => \format_selfstudy\local\path_repository::ITEM_MILESTONE,
                'title' => 'Fallback milestone',
                'configdata' => ['milestonekey' => 'fallback'],
            ],
            [
                'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
                'cmid' => (int)$page->cmid,
            ],
        ]);

        $progress = \format_selfstudy\local\path_progress::calculate($course, $pathid, (int)$user->id);

        $this->assertSame(1, $progress->total);
        $this->assertSame(0, $progress->complete);
    }
}
