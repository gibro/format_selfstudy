<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for the derived learning path competency profile.
 *
 * @package    format_selfstudy
 * @category   test
 */
class format_selfstudy_path_competency_service_test extends advanced_testcase {

    public function test_profile_is_derived_from_station_competencies(): void {
        $this->resetAfterTest();
        [$course, $pathid, $competencies, $pages] = $this->create_course_with_competency_path();
        $service = new \format_selfstudy\local\path_competency_service();

        $profile = $service->profile_for_path((int)$course->id, $pathid);

        $this->assertCount(2, $profile, 'Only competencies used by path stations belong to the profile.');
        $byid = [];
        foreach ($profile as $entry) {
            $byid[$entry->id] = $entry;
        }
        $this->assertArrayHasKey((int)$competencies['shared']->get('id'), $byid);
        $this->assertArrayHasKey((int)$competencies['single']->get('id'), $byid);
        $this->assertArrayNotHasKey((int)$competencies['unused']->get('id'), $byid,
            'A course competency without station links must not appear.');
        $this->assertSame(2, $byid[(int)$competencies['shared']->get('id')]->stationcount);
        $this->assertSame(1, $byid[(int)$competencies['single']->get('id')]->stationcount);
        foreach ($profile as $entry) {
            $this->assertNotSame('', trim((string)$entry->label));
        }
    }

    public function test_profile_is_empty_without_station_links(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy', 'numsections' => 1]);
        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Plain station'],
            ['section' => 1]);

        $repository = new \format_selfstudy\local\path_repository();
        $pathid = $repository->create_path((int)$course->id, [
            'name' => 'Plain path',
            'enabled' => 1,
            'userid' => 0,
        ]);
        $repository->replace_path_items($pathid, [
            [
                'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
                'cmid' => (int)$page->cmid,
            ],
        ]);

        $service = new \format_selfstudy\local\path_competency_service();
        $this->assertSame([], $service->profile_for_path((int)$course->id, $pathid));
        $this->assertSame([], $service->profile_labels_for_path((int)$course->id, $pathid));
    }

    public function test_profile_is_empty_when_competencies_are_disabled(): void {
        $this->resetAfterTest();
        [$course, $pathid] = $this->create_course_with_competency_path();

        set_config('enabled', 0, 'core_competency');

        $service = new \format_selfstudy\local\path_competency_service();
        $this->assertSame([], $service->profile_for_path((int)$course->id, $pathid));
    }

    public function test_stations_for_competency_returns_linked_stations_only(): void {
        $this->resetAfterTest();
        [$course, $pathid, $competencies, $pages] = $this->create_course_with_competency_path();
        $service = new \format_selfstudy\local\path_competency_service();

        $stations = $service->stations_for_competency((int)$course->id, $pathid,
            (int)$competencies['single']->get('id'));
        $this->assertCount(1, $stations);
        $this->assertSame((int)$pages['second']->cmid, $stations[0]->cmid);

        $stations = $service->stations_for_competency((int)$course->id, $pathid,
            (int)$competencies['shared']->get('id'));
        $this->assertSame([(int)$pages['first']->cmid, (int)$pages['second']->cmid],
            array_map(static function(\stdClass $station): int {
                return $station->cmid;
            }, $stations));

        $this->assertSame([], $service->stations_for_competency((int)$course->id, $pathid,
            (int)$competencies['unused']->get('id')));
    }

    /**
     * Creates a selfstudy course whose path stations use core competencies.
     *
     * Course competencies: shared (both stations), single (second station only)
     * and unused (no station). A third activity outside the path also links the
     * unused competency to prove that only path stations count.
     *
     * @return array [$course, $pathid, $competencies, $pages]
     */
    private function create_course_with_competency_path(): array {
        $this->setAdminUser();
        set_config('enabled', 1, 'core_competency');
        $generator = $this->getDataGenerator();
        $competencygenerator = $generator->get_plugin_generator('core_competency');

        $course = $generator->create_course(['format' => 'selfstudy', 'numsections' => 1]);
        $first = $generator->create_module('page', ['course' => $course->id, 'name' => 'First station'],
            ['section' => 1]);
        $second = $generator->create_module('page', ['course' => $course->id, 'name' => 'Second station'],
            ['section' => 1]);
        $outside = $generator->create_module('page', ['course' => $course->id, 'name' => 'Outside path'],
            ['section' => 1]);

        $framework = $competencygenerator->create_framework();
        $competencies = [];
        foreach (['shared', 'single', 'unused'] as $key) {
            $competency = $competencygenerator->create_competency([
                'competencyframeworkid' => $framework->get('id'),
                'shortname' => 'Competency ' . $key,
            ]);
            $competencygenerator->create_course_competency([
                'courseid' => $course->id,
                'competencyid' => $competency->get('id'),
            ]);
            $competencies[$key] = $competency;
        }

        \core_competency\api::add_competency_to_course_module((int)$first->cmid,
            (int)$competencies['shared']->get('id'));
        \core_competency\api::add_competency_to_course_module((int)$second->cmid,
            (int)$competencies['shared']->get('id'));
        \core_competency\api::add_competency_to_course_module((int)$second->cmid,
            (int)$competencies['single']->get('id'));
        \core_competency\api::add_competency_to_course_module((int)$outside->cmid,
            (int)$competencies['unused']->get('id'));

        $repository = new \format_selfstudy\local\path_repository();
        $pathid = $repository->create_path((int)$course->id, [
            'name' => 'Competency path',
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

        return [$course, $pathid, $competencies, ['first' => $first, 'second' => $second, 'outside' => $outside]];
    }
}
