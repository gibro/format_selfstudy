<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for the derived learning path evidence.
 *
 * @package    format_selfstudy
 * @category   test
 */
class format_selfstudy_path_evidence_service_test extends advanced_testcase {

    public function test_partial_progress_is_not_complete(): void {
        $this->resetAfterTest();
        [$course, $user, $pathid, $pages] = $this->create_course_with_two_stations();
        $this->complete_module($course, $pages['first'], $user);

        $evidence = (new \format_selfstudy\local\path_evidence_service())
            ->build_evidence($course, $pathid, (int)$user->id);

        $this->assertTrue($evidence->hasdata);
        $this->assertTrue($evidence->started);
        $this->assertFalse($evidence->iscomplete);
        $this->assertSame(1, $evidence->progress->complete);
        $this->assertSame(2, $evidence->progress->total);

        $done = array_values(array_filter($evidence->stations, static function(\stdClass $s): bool {
            return $s->iscomplete;
        }));
        $this->assertCount(1, $done);
        $this->assertSame('First station', $done[0]->title);
    }

    public function test_all_stations_complete_marks_path_complete(): void {
        $this->resetAfterTest();
        [$course, $user, $pathid, $pages] = $this->create_course_with_two_stations();
        $this->complete_module($course, $pages['first'], $user);
        $this->complete_module($course, $pages['second'], $user);

        $service = new \format_selfstudy\local\path_evidence_service();
        $evidence = $service->build_evidence($course, $pathid, (int)$user->id);

        $this->assertTrue($evidence->iscomplete);
        $this->assertSame(2, $evidence->progress->complete);
        $this->assertTrue($service->is_path_complete($course, $pathid, (int)$user->id));
    }

    public function test_empty_evidence_without_started_path(): void {
        $this->resetAfterTest();
        [$course, $user, $pathid] = $this->create_course_with_two_stations();

        $evidence = (new \format_selfstudy\local\path_evidence_service())
            ->build_evidence($course, $pathid, (int)$user->id);

        $this->assertTrue($evidence->hasdata);
        $this->assertFalse($evidence->started);
        $this->assertFalse($evidence->iscomplete);
        $this->assertSame([], $evidence->competencies);
    }

    public function test_competency_achievement_is_reflected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enabled', 1, 'core_competency');
        [$course, $user, $pathid, $pages] = $this->create_course_with_two_stations();

        $competencygenerator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $competencygenerator->create_framework();
        $achieved = $competencygenerator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'shortname' => 'Achieved competency',
        ]);
        $open = $competencygenerator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'shortname' => 'Open competency',
        ]);
        foreach ([$achieved, $open] as $competency) {
            $competencygenerator->create_course_competency([
                'courseid' => $course->id,
                'competencyid' => $competency->get('id'),
            ]);
        }
        \core_competency\api::add_competency_to_course_module((int)$pages['first']->cmid, (int)$achieved->get('id'));
        \core_competency\api::add_competency_to_course_module((int)$pages['second']->cmid, (int)$open->get('id'));

        // Mark the learner proficient in one competency.
        \core_competency\api::grade_competency_in_course($course->id, (int)$user->id, (int)$achieved->get('id'), 4, 'ok');

        $evidence = (new \format_selfstudy\local\path_evidence_service())
            ->build_evidence($course, $pathid, (int)$user->id);

        $this->assertSame(2, $evidence->competenciestotal);
        $this->assertSame(1, $evidence->competenciesachieved);
        $byid = [];
        foreach ($evidence->competencies as $entry) {
            $byid[$entry->id] = $entry->achieved;
        }
        $this->assertTrue($byid[(int)$achieved->get('id')]);
        $this->assertFalse($byid[(int)$open->get('id')]);
    }

    public function test_course_completion_flag(): void {
        $this->resetAfterTest();
        [$course, $user, $pathid] = $this->create_course_with_two_stations();

        $evidence = (new \format_selfstudy\local\path_evidence_service())
            ->build_evidence($course, $pathid, (int)$user->id);
        $this->assertFalse($evidence->coursecomplete);
    }

    /**
     * Creates a selfstudy course, an enrolled user and a two-station path.
     *
     * @return array [$course, $user, $pathid, $pages]
     */
    private function create_course_with_two_stations(): array {
        set_config('enablecompletion', 1);
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy', 'numsections' => 1, 'enablecompletion' => 1]);
        $first = $generator->create_module('page', ['course' => $course->id, 'name' => 'First station',
            'completion' => COMPLETION_TRACKING_MANUAL], ['section' => 1]);
        $second = $generator->create_module('page', ['course' => $course->id, 'name' => 'Second station',
            'completion' => COMPLETION_TRACKING_MANUAL], ['section' => 1]);
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id);

        $repository = new \format_selfstudy\local\path_repository();
        $pathid = $repository->create_path((int)$course->id, [
            'name' => 'Evidence path',
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
                'cmid' => (int)$first->cmid,
            ],
            [
                'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
                'cmid' => (int)$second->cmid,
            ],
        ]);

        return [$course, $user, $pathid, ['first' => $first, 'second' => $second]];
    }

    /**
     * Marks a course module complete for a learner.
     *
     * @param \stdClass $course
     * @param \stdClass $module
     * @param \stdClass $user
     */
    private function complete_module(\stdClass $course, \stdClass $module, \stdClass $user): void {
        $completion = new \completion_info($course);
        $cm = get_coursemodule_from_id('page', (int)$module->cmid, 0, false, MUST_EXIST);
        $completion->update_state($cm, COMPLETION_COMPLETE, (int)$user->id);
    }
}
