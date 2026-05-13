<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for the learner-facing base view model.
 *
 * @package    format_selfstudy
 * @category   test
 */
class format_selfstudy_base_view_test extends advanced_testcase {

    public function test_base_view_uses_active_published_revision_without_learner_choice(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy', 'numsections' => 1]);
        $publishedpage = $generator->create_module('page', ['course' => $course->id, 'name' => 'Published station'],
            ['section' => 1]);
        $draftpage = $generator->create_module('page', ['course' => $course->id, 'name' => 'Draft station'],
            ['section' => 1]);
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id);

        $repository = new \format_selfstudy\local\path_repository();
        $pathid = $repository->create_path((int)$course->id, [
            'name' => 'Published base path',
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
                'cmid' => (int)$publishedpage->cmid,
            ],
        ]);

        $builder = new \format_selfstudy\local\path_snapshot_builder();
        $snapshotrepository = new \format_selfstudy\local\path_snapshot_repository();
        $snapshotrepository->save_revision($pathid, (int)$course->id, 0,
            $builder->build_snapshot($course, $repository->get_path_with_items($pathid)), (int)$user->id, 123456);

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

        $view = \format_selfstudy\local\base_view::create($course, (int)$user->id);
        $titles = array_map(static function(\stdClass $entry): string {
            return $entry->title;
        }, $view->outline);

        $this->assertNotNull($view->path);
        $this->assertSame($pathid, (int)$view->path->id);
        $this->assertSame(\format_selfstudy\local\path_runtime::SOURCE_SNAPSHOT, $view->source);
        $this->assertSame(1, $view->revision);
        $this->assertSame(123456, $view->timepublished);
        $this->assertContains('Published milestone', $titles);
        $this->assertContains('Published station', $titles);
        $this->assertNotContains('Draft milestone', $titles);
        $this->assertNotContains('Draft station', $titles);
    }

    public function test_base_view_ignores_disabled_learner_choice_and_falls_back_to_published_path(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy', 'numsections' => 1]);
        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Active published station'],
            ['section' => 1]);
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id);

        $repository = new \format_selfstudy\local\path_repository();
        $disabledpathid = $repository->create_path((int)$course->id, [
            'name' => 'Old choice',
            'enabled' => 0,
            'userid' => 0,
        ]);
        $publishedpathid = $repository->create_path((int)$course->id, [
            'name' => 'Current published path',
            'enabled' => 1,
            'userid' => 0,
        ]);
        $repository->replace_path_items($publishedpathid, [
            [
                'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
                'cmid' => (int)$page->cmid,
            ],
        ]);
        $repository->set_active_path((int)$course->id, (int)$user->id, $disabledpathid);

        $builder = new \format_selfstudy\local\path_snapshot_builder();
        $snapshotrepository = new \format_selfstudy\local\path_snapshot_repository();
        $snapshotrepository->save_revision($publishedpathid, (int)$course->id, 0,
            $builder->build_snapshot($course, $repository->get_path_with_items($publishedpathid)));

        $view = \format_selfstudy\local\base_view::create($course, (int)$user->id);

        $this->assertNotNull($view->path);
        $this->assertSame($publishedpathid, (int)$view->path->id);
        $this->assertSame(\format_selfstudy\local\path_runtime::SOURCE_SNAPSHOT, $view->source);
    }
}
