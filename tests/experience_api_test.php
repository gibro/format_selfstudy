<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy_tests {

    /**
     * Test renderer for the selfstudy experience API.
     */
    class renderer implements \format_selfstudy\local\experience_renderer_interface {

        public function supports(\stdClass $course, \stdClass $baseview, \stdClass $config): bool {
            return empty($config->unsupported);
        }

        public function render_course_entry(\stdClass $course, \stdClass $baseview, \stdClass $config): string {
            if (!empty($config->throwrender)) {
                throw new \coding_exception('Boom');
            }
            return '<a href="/course/view.php?id=' . (int)$course->id . '">Experience</a>';
        }

        public function get_activity_navigation(\stdClass $course, \stdClass $baseview, \cm_info $cm,
                \stdClass $config): ?\stdClass {
            return (object)[
                'mapurl' => '/course/view.php?id=' . (int)$course->id,
            ];
        }
    }
}

namespace {

/**
 * Tests for the optional selfstudy experience API.
 *
 * @package    format_selfstudy
 * @category   test
 */
class format_selfstudy_experience_api_test extends advanced_testcase {

    public function test_repository_saves_reads_and_marks_missing_experiences(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'selfstudy']);

        $repository = new \format_selfstudy\local\experience_repository();
        $repository->save_course_experience((int)$course->id, 'selfstudyexperience_demo',
            ['colour' => '#123456'], true, 7, 2);

        $records = $repository->get_course_experiences((int)$course->id);
        $this->assertCount(1, $records);
        $this->assertSame('selfstudyexperience_demo', $records[0]->component);
        $this->assertSame(1, (int)$records[0]->enabled);
        $this->assertSame(7, (int)$records[0]->sortorder);
        $this->assertSame(2, (int)$records[0]->configschema);
        $this->assertSame('#123456', $repository->decode_config($records[0])->colour);

        $repository->mark_missing_experiences((int)$course->id, []);
        $record = $repository->get_course_experience((int)$course->id, 'selfstudyexperience_demo');
        $this->assertSame(1, (int)$record->missing);

        $repository->mark_missing_experiences((int)$course->id, ['selfstudyexperience_demo']);
        $record = $repository->get_course_experience((int)$course->id, 'selfstudyexperience_demo');
        $this->assertSame(0, (int)$record->missing);
    }

    public function test_registry_reports_available_disabled_missing_and_incompatible_statuses(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'selfstudy']);
        $baseview = (object)['path' => null, 'outline' => []];

        $repository = new \format_selfstudy\local\experience_repository();
        $repository->save_course_experience((int)$course->id, 'selfstudyexperience_available', [], true, 1);
        $repository->save_course_experience((int)$course->id, 'selfstudyexperience_disabled', [], false, 2);
        $repository->save_course_experience((int)$course->id, 'selfstudyexperience_missing', [], true, 3);
        $repository->save_course_experience((int)$course->id, 'selfstudyexperience_incompatible',
            ['unsupported' => true], true, 4);

        $metadata = [
            'selfstudyexperience_available' => $this->metadata('selfstudyexperience_available'),
            'selfstudyexperience_disabled' => $this->metadata('selfstudyexperience_disabled'),
            'selfstudyexperience_incompatible' => $this->metadata('selfstudyexperience_incompatible'),
        ];
        $registry = new \format_selfstudy\local\experience_registry($repository, $metadata);

        $statuses = [];
        foreach ($registry->get_course_experiences($course, $baseview) as $entry) {
            $statuses[$entry->component] = $entry->status;
        }

        $this->assertSame(\format_selfstudy\local\experience_registry::STATUS_AVAILABLE,
            $statuses['selfstudyexperience_available']);
        $this->assertSame(\format_selfstudy\local\experience_registry::STATUS_DISABLED,
            $statuses['selfstudyexperience_disabled']);
        $this->assertSame(\format_selfstudy\local\experience_registry::STATUS_MISSING,
            $statuses['selfstudyexperience_missing']);
        $this->assertSame(\format_selfstudy\local\experience_registry::STATUS_INCOMPATIBLE,
            $statuses['selfstudyexperience_incompatible']);

        $renderable = $registry->get_renderable_experiences($course, $baseview);
        $this->assertCount(1, $renderable);
        $this->assertSame('selfstudyexperience_available', $renderable[0]->component);
    }

    public function test_activity_navigation_uses_enabled_available_experience_only(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy', 'numsections' => 1]);
        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Station'], ['section' => 1]);
        $baseview = (object)['path' => null, 'outline' => []];
        $cm = get_fast_modinfo($course)->get_cm((int)$page->cmid);

        $repository = new \format_selfstudy\local\experience_repository();
        $repository->save_course_experience((int)$course->id, 'selfstudyexperience_demo', [], true, 1);
        $registry = new \format_selfstudy\local\experience_registry($repository, [
            'selfstudyexperience_demo' => $this->metadata('selfstudyexperience_demo'),
        ]);

        $navigation = $registry->get_activity_navigation($course, $baseview, $cm);
        $this->assertNotNull($navigation);
        $this->assertSame('/course/view.php?id=' . (int)$course->id, $navigation->mapurl);

        $repository->save_course_experience((int)$course->id, 'selfstudyexperience_demo', [], false, 1);
        $this->assertNull($registry->get_activity_navigation($course, $baseview, $cm));
    }

    public function test_transfer_exports_and_imports_experience_configuration(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy', 'numsections' => 1]);
        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Station'], ['section' => 1]);

        $pathrepository = new \format_selfstudy\local\path_repository();
        $experiencerepository = new \format_selfstudy\local\experience_repository();
        $pathid = $pathrepository->create_path((int)$course->id, [
            'name' => 'Transferpfad',
            'enabled' => 1,
            'userid' => 0,
        ]);
        $pathrepository->replace_path_items($pathid, [[
            'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
            'cmid' => (int)$page->cmid,
        ]]);
        $experiencerepository->save_course_experience((int)$course->id, 'selfstudyexperience_missing',
            ['mode' => 'legacy'], false, 5, 3, true);

        $transfer = new \format_selfstudy\local\path_transfer($pathrepository, $experiencerepository);
        $payload = $transfer->export_path($course, $pathid);
        $this->assertSame(3, $payload['version']);
        $this->assertSame('selfstudyexperience_missing', $payload['experiences'][0]['component']);

        $transfer->import_path($course, $payload);
        $record = $experiencerepository->get_course_experience((int)$course->id, 'selfstudyexperience_missing');

        $this->assertNotEmpty($record);
        $this->assertSame(1, (int)$record->missing);
        $this->assertSame(0, (int)$record->enabled);
        $this->assertSame('legacy', $experiencerepository->decode_config($record)->mode);
    }

    public function test_learningmap_builder_maps_outline_to_playful_nodes(): void {
        $this->resetAfterTest();
        require_once(__DIR__ . '/../experience/learningmap/classes/map_builder.php');

        $course = $this->getDataGenerator()->create_course(['format' => 'selfstudy']);
        $baseview = (object)[
            'path' => (object)['id' => 42],
            'revision' => 3,
            'source' => 'snapshot',
            'outline' => [
                (object)[
                    'id' => 1,
                    'type' => \format_selfstudy\local\path_repository::ITEM_MILESTONE,
                    'title' => 'Start',
                    'status' => 'open',
                    'level' => 0,
                    'cmid' => 0,
                ],
                (object)[
                    'id' => 2,
                    'type' => \format_selfstudy\local\path_repository::ITEM_STATION,
                    'title' => 'Station',
                    'status' => 'recommended',
                    'level' => 1,
                    'cmid' => 123,
                    'url' => '/mod/page/view.php?id=123',
                ],
            ],
        ];

        $model = (new \selfstudyexperience_learningmap\map_builder())->build($course, $baseview, (object)[
            'theme' => 'adventure',
            'avatarenabled' => true,
            'routeenabled' => true,
        ]);

        $this->assertSame(42, $model->pathid);
        $this->assertSame(3, $model->revision);
        $this->assertCount(2, $model->nodes);
        $this->assertSame('checkpoint', $model->nodes[0]->gamestate);
        $this->assertSame('next', $model->nodes[1]->gamestate);
        $this->assertSame('cm-123', $model->currentkey);
        $this->assertCount(1, $model->connections);
    }

    /**
     * Returns metadata for the test renderer.
     *
     * @param string $component
     * @return stdClass
     */
    private function metadata(string $component): stdClass {
        return (object)[
            'component' => $component,
            'name' => 'Demo',
            'description' => 'Demo renderer',
            'schema' => 1,
            'features' => ['test'],
            'rendererclass' => '\\format_selfstudy_tests\\renderer',
        ];
    }
}
}
