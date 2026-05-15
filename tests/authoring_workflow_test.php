<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for teacher authoring workflow status calculation.
 *
 * @package    format_selfstudy
 * @category   test
 */
class format_selfstudy_authoring_workflow_test extends advanced_testcase {

    public function test_course_without_path_reports_path_not_started(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'selfstudy', 'numsections' => 1]);

        $state = (new \format_selfstudy\local\authoring_workflow())->get_state($course);

        $this->assertSame(0, (int)$state->pathid);
        $this->assertFalse($state->publishable);
        $this->assertSame(\format_selfstudy\local\authoring_workflow::STATUS_NOTSTARTED,
            $this->step_status($state, 'path'));
        $this->assertSame(\format_selfstudy\local\authoring_workflow::STATUS_NOTSTARTED,
            $this->step_status($state, 'publish'));
    }

    public function test_course_with_activity_but_empty_path_needs_path_work(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy', 'numsections' => 1]);
        $generator->create_module('page', ['course' => $course->id, 'name' => 'Station'], ['section' => 1]);

        $repository = new \format_selfstudy\local\path_repository();
        $pathid = $repository->create_path((int)$course->id, [
            'name' => 'Draft path',
            'enabled' => 0,
            'userid' => 0,
        ]);

        $state = (new \format_selfstudy\local\authoring_workflow($repository))->get_state($course, $pathid);

        $this->assertSame($pathid, (int)$state->pathid);
        $this->assertSame(\format_selfstudy\local\authoring_workflow::STATUS_READY,
            $this->step_status($state, 'structure'));
        $this->assertSame(\format_selfstudy\local\authoring_workflow::STATUS_NEEDSWORK,
            $this->step_status($state, 'path'));
    }

    public function test_published_revision_and_draft_change_are_visible(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy', 'numsections' => 1]);
        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Station'], ['section' => 1]);

        $repository = new \format_selfstudy\local\path_repository();
        $pathid = $repository->create_path((int)$course->id, [
            'name' => 'Published path',
            'enabled' => 1,
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
        $snapshots->save_revision($pathid, (int)$course->id, 0, (object)[
            'schema' => 1,
            'json' => json_encode(['schema' => 1, 'root' => [], 'nodes' => [], 'rules' => []]),
            'sourcehash' => str_repeat('f', 64),
        ], 2, 100);
        $repository->update_path($pathid, ['name' => 'Published path changed', 'enabled' => 1]);

        $state = (new \format_selfstudy\local\authoring_workflow($repository, $snapshots))->get_state($course, $pathid);

        $this->assertSame(1, (int)$state->activepublishedrevision);
        $this->assertTrue($state->draftchanged);
        $this->assertSame(\format_selfstudy\local\authoring_workflow::STATUS_WARNING,
            $this->step_status($state, 'publish'));
        $this->assertCount(1, $state->revisions);
    }

    /**
     * Returns one workflow step status.
     *
     * @param stdClass $state
     * @param string $key
     * @return string
     */
    private function step_status(stdClass $state, string $key): string {
        foreach ($state->steps as $step) {
            if ($step->key === $key) {
                return $step->status;
            }
        }
        $this->fail('Missing workflow step: ' . $key);
    }
}
