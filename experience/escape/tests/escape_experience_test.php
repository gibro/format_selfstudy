<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for the Escape selfstudy experience.
 *
 * @package    format_selfstudy
 * @category   test
 */
class format_selfstudy_escape_experience_test extends advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/../experience/escape/classes/room_builder.php');
        require_once(__DIR__ . '/../experience/escape/classes/renderer.php');
        require_once(__DIR__ . '/../experience/escape/classes/config_form.php');
        require_once(__DIR__ . '/../experience/escape/classes/experience.php');
    }

    public function test_room_builder_groups_outline_into_rooms(): void {
        $baseview = (object)[
            'path' => (object)['id' => 1],
            'progress' => (object)['complete' => 2, 'total' => 4, 'percentage' => 50],
            'outline' => [
                $this->milestone('Room one'),
                $this->station('Station A', 'complete'),
                $this->station('Station B', 'open'),
                $this->milestone('Room two'),
                $this->station('Station C', 'locked'),
                $this->station('Station D', 'locked'),
            ],
        ];

        $model = (new \selfstudyexperience_escape\room_builder())
            ->build($this->fake_course(), $baseview, (object)['theme' => 'dungeon']);

        $this->assertCount(2, $model->rooms);
        $this->assertSame(1, $model->keycount);

        [$first, $second] = $model->rooms;
        $this->assertSame('Room one', $first->title);
        $this->assertSame('open', $first->status);
        $this->assertSame(1, $first->keycount);
        $this->assertCount(2, $first->hotspots);

        $this->assertSame('Room two', $second->title);
        $this->assertSame('locked', $second->status);
        $this->assertTrue($second->locked);
        $this->assertSame(0, $second->keycount);
    }

    public function test_room_is_complete_when_all_hotspots_done(): void {
        $baseview = (object)[
            'path' => (object)['id' => 1],
            'progress' => (object)['complete' => 2, 'total' => 2, 'percentage' => 100],
            'outline' => [
                $this->milestone('Only room'),
                $this->station('A', 'complete'),
                $this->station('B', 'complete'),
            ],
        ];

        $model = (new \selfstudyexperience_escape\room_builder())
            ->build($this->fake_course(), $baseview, (object)[]);

        $this->assertCount(1, $model->rooms);
        $this->assertSame('complete', $model->rooms[0]->status);
        $this->assertTrue($model->rooms[0]->iscomplete);
    }

    public function test_path_without_milestones_yields_single_room(): void {
        $baseview = (object)[
            'path' => (object)['id' => 1],
            'progress' => (object)['complete' => 0, 'total' => 2, 'percentage' => 0],
            'outline' => [
                $this->station('Lonely A', 'open'),
                $this->station('Lonely B', 'open'),
            ],
        ];

        $model = (new \selfstudyexperience_escape\room_builder())
            ->build($this->fake_course(), $baseview, (object)[]);

        $this->assertCount(1, $model->rooms);
        $this->assertCount(2, $model->rooms[0]->hotspots);
    }

    public function test_renderer_supports_and_renders(): void {
        $course = $this->fake_course();
        $baseview = (object)[
            'path' => (object)['id' => 1],
            'progress' => (object)['complete' => 1, 'total' => 2, 'percentage' => 50],
            'outline' => [
                $this->milestone('Vault'),
                $this->station('Crack the code', 'complete'),
                $this->station('Find the key', 'open'),
            ],
        ];
        $config = (object)['theme' => 'spaceship', 'introstory' => 'Escape the lab.', 'showinventory' => 1];
        $renderer = new \selfstudyexperience_escape\renderer();

        $this->assertTrue($renderer->supports($course, $baseview, $config));
        $html = $renderer->render_course_entry($course, $baseview, $config);
        $this->assertStringContainsString('format-selfstudy-escape-theme-spaceship', $html);
        $this->assertStringContainsString('Vault', $html);
        $this->assertStringContainsString('Crack the code', $html);
        $this->assertStringContainsString('Escape the lab.', $html);
    }

    public function test_renderer_handles_locked_hotspots_without_url(): void {
        $course = $this->fake_course();
        $baseview = (object)[
            'path' => (object)['id' => 1],
            'progress' => (object)['complete' => 0, 'total' => 2, 'percentage' => 0],
            'outline' => [
                $this->milestone('Sealed vault'),
                (object)['type' => 'station', 'title' => 'Locked door', 'status' => 'locked', 'url' => null],
            ],
        ];
        $config = (object)['theme' => 'dungeon', 'revealmystery' => 1];

        // A locked, unlinked hotspot must render without a type error.
        $html = (new \selfstudyexperience_escape\renderer())->render_course_entry($course, $baseview, $config);
        $this->assertStringContainsString('Locked door', $html);
        $this->assertStringContainsString('format-selfstudy-escape-hotspot-locked', $html);
    }

    public function test_hotspot_roles_follow_completion_settings(): void {
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy', 'numsections' => 1, 'enablecompletion' => 1]);
        $item = $generator->create_module('page', ['course' => $course->id, 'name' => 'Collectable',
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => COMPLETION_VIEW_REQUIRED],
            ['section' => 1]);
        $riddle = $generator->create_module('assign', ['course' => $course->id, 'name' => 'Riddle',
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionusegrade' => 1], ['section' => 1]);
        $key = $generator->create_module('page', ['course' => $course->id, 'name' => 'Plain key',
            'completion' => COMPLETION_TRACKING_MANUAL], ['section' => 1]);

        $baseview = (object)[
            'path' => (object)['id' => 1],
            'progress' => (object)['complete' => 1, 'total' => 3, 'percentage' => 33],
            'outline' => [
                $this->milestone('Roles room'),
                (object)['type' => 'station', 'title' => 'Collectable', 'status' => 'complete',
                    'url' => 'https://example.test/1', 'cmid' => (int)$item->cmid],
                (object)['type' => 'station', 'title' => 'Riddle', 'status' => 'open',
                    'url' => 'https://example.test/2', 'cmid' => (int)$riddle->cmid],
                (object)['type' => 'station', 'title' => 'Plain key', 'status' => 'open',
                    'url' => 'https://example.test/3', 'cmid' => (int)$key->cmid],
            ],
        ];

        $model = (new \selfstudyexperience_escape\room_builder())->build($course, $baseview, (object)[]);

        $roles = array_map(static function(\stdClass $hotspot): string {
            return $hotspot->role;
        }, $model->rooms[0]->hotspots);
        $this->assertSame(['item', 'riddle', 'key'], $roles);
        $this->assertSame(1, $model->itemtotal);
        $this->assertSame(1, $model->itemcount, 'The completed collectable counts as collected.');
        $this->assertSame(1, $model->riddletotal);
        $this->assertSame(0, $model->riddlesolved);
    }

    public function test_renderer_does_not_support_empty_path(): void {
        $renderer = new \selfstudyexperience_escape\renderer();
        $empty = (object)['path' => null, 'progress' => (object)[], 'outline' => []];
        $this->assertFalse($renderer->supports($this->fake_course(), $empty, (object)[]));
    }

    public function test_config_form_defines_expected_fields(): void {
        $fields = \selfstudyexperience_escape\config_form::get_field_definitions($this->fake_course());
        $this->assertArrayHasKey('theme', $fields);
        $this->assertArrayHasKey('introstory', $fields);
        $this->assertSame('textarea', $fields['introstory']['type']);
        $this->assertArrayHasKey('showinventory', $fields);
        $this->assertArrayHasKey('revealmystery', $fields);
    }

    public function test_registry_discovers_escape_experience(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'selfstudy']);
        $registry = new \format_selfstudy\local\experience_registry();

        $components = [];
        foreach ($registry->get_course_experiences($course) as $entry) {
            $components[$entry->component] = $entry;
        }

        $this->assertArrayHasKey('selfstudyexperience_escape', $components);
        $escape = $components['selfstudyexperience_escape'];
        $this->assertFalse((bool)$escape->missing);
        $this->assertSame('\\selfstudyexperience_escape\\config_form', $escape->configformclass);
    }

    /**
     * Returns a milestone outline entry.
     *
     * @param string $title
     * @return \stdClass
     */
    private function milestone(string $title): \stdClass {
        return (object)['type' => 'milestone', 'title' => $title, 'status' => 'open'];
    }

    /**
     * Returns a station outline entry.
     *
     * @param string $title
     * @param string $status
     * @return \stdClass
     */
    private function station(string $title, string $status): \stdClass {
        return (object)[
            'type' => 'station',
            'title' => $title,
            'status' => $status,
            'url' => 'https://example.test/mod/page/view.php?id=1',
        ];
    }

    /**
     * Returns a minimal course record for renderer calls.
     *
     * @return \stdClass
     */
    private function fake_course(): \stdClass {
        return (object)['id' => 1, 'fullname' => 'Escape course'];
    }
}
