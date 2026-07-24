<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for the inline editor hook, the direct publish step and the
 * learning view fields in the course settings.
 *
 * @package    format_selfstudy
 * @category   test
 */
class format_selfstudy_inline_ui_test extends advanced_testcase {

    protected function tearDown(): void {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        parent::tearDown();
    }

    public function test_hook_ignores_get_requests(): void {
        $this->resetAfterTest();
        [$course, $repository, $pathid] = $this->create_course_with_draft_path();
        $this->prepare_course_page($course);
        $_POST['selfstudypublish'] = 1;
        $_POST['pathid'] = $pathid;
        $_POST['sesskey'] = sesskey();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        \format_selfstudy\hook_callbacks::before_http_headers($this->make_hook());

        $this->assertSame(0, (int)$repository->get_path($pathid)->enabled,
            'A GET request must never trigger the inline publish handler.');
    }

    public function test_hook_ignores_other_page_types(): void {
        global $PAGE;
        $this->resetAfterTest();
        [$course, $repository, $pathid] = $this->create_course_with_draft_path();
        $this->prepare_course_page($course);
        $PAGE->set_pagetype('site-index');
        $_POST['selfstudypublish'] = 1;
        $_POST['pathid'] = $pathid;
        $_POST['sesskey'] = sesskey();
        $_SERVER['REQUEST_METHOD'] = 'POST';

        \format_selfstudy\hook_callbacks::before_http_headers($this->make_hook());

        $this->assertSame(0, (int)$repository->get_path($pathid)->enabled,
            'Only the selfstudy course page may run the inline handlers.');
    }

    public function test_hook_publishes_path_from_course_page_post(): void {
        $this->resetAfterTest();
        [$course, $repository, $pathid] = $this->create_course_with_draft_path();
        $this->prepare_course_page($course);
        $_POST['selfstudypublish'] = 1;
        $_POST['pathid'] = $pathid;
        $_POST['sesskey'] = sesskey();
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $redirected = false;
        try {
            \format_selfstudy\hook_callbacks::before_http_headers($this->make_hook());
        } catch (\moodle_exception $exception) {
            // In test mode redirect() aborts with an exception; reaching it
            // means the handler ran through the whole publish flow.
            $redirected = true;
        }

        $this->assertTrue($redirected, 'The publish handler must end in a redirect.');
        $path = $repository->get_path($pathid);
        $this->assertSame(1, (int)$path->enabled);
        $this->assertSame(1, (int)$path->issource, 'The first published path becomes the availability source.');
        $revision = (new \format_selfstudy\local\path_snapshot_repository())->get_active_revision($pathid);
        $this->assertNotNull($revision);
        $this->assertSame(\format_selfstudy\local\path_snapshot_repository::STATUS_PUBLISHED, $revision->status);
    }

    public function test_path_editor_setsource_post_switches_the_source(): void {
        global $FORMAT_SELFSTUDY_PATH_EDITOR_FUNCTIONS_ONLY;
        $this->resetAfterTest();
        [$course, $repository, $firstpathid] = $this->create_course_with_draft_path();
        $publisher = new \format_selfstudy\local\path_publish_service($repository);
        $publisher->publish_course_path($course, $firstpathid);

        $generator = $this->getDataGenerator();
        $otherpage = $generator->create_module('page', ['course' => $course->id, 'name' => 'Second station',
            'completion' => COMPLETION_TRACKING_MANUAL], ['section' => 1]);
        $secondpathid = $repository->create_path((int)$course->id, [
            'name' => 'Second path',
            'enabled' => 0,
            'userid' => 0,
        ]);
        $repository->replace_path_items($secondpathid, [
            [
                'itemtype' => \format_selfstudy\local\path_repository::ITEM_MILESTONE,
                'title' => 'Start',
                'configdata' => ['milestonekey' => 'start'],
            ],
            [
                'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
                'cmid' => (int)$otherpage->cmid,
            ],
        ]);
        $publisher->publish_course_path($course, $secondpathid);
        $this->assertSame($firstpathid, (int)$repository->get_source_path((int)$course->id)->id);

        $FORMAT_SELFSTUDY_PATH_EDITOR_FUNCTIONS_ONLY = true;
        require_once(__DIR__ . '/../path_editor.php');
        $this->setAdminUser();
        $_POST['sesskey'] = sesskey();

        $redirected = false;
        try {
            format_selfstudy_path_editor_handle_post($course,
                new moodle_url('/course/format/selfstudy/path_editor.php', ['id' => $course->id]),
                $secondpathid, 'setsource');
        } catch (\moodle_exception $exception) {
            $redirected = true;
        }

        $this->assertTrue($redirected, 'The setsource action must end in a redirect.');
        $this->assertSame($secondpathid, (int)$repository->get_source_path((int)$course->id)->id);
    }

    public function test_learningmap_experience_provides_course_settings_fields(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy', 'numsections' => 1]);

        $repository = new \format_selfstudy\local\experience_repository();
        $registry = new \format_selfstudy\local\experience_registry($repository);
        $entries = $registry->get_course_experiences($course);

        $learningmap = null;
        foreach ($entries as $entry) {
            if ($entry->component === 'selfstudyexperience_learningmap') {
                $learningmap = $entry;
            }
        }

        $this->assertNotNull($learningmap, 'The learningmap learning view must be registered.');
        $this->assertNotSame('', (string)$learningmap->configformclass);
        $this->assertTrue(class_exists($learningmap->configformclass));
        $this->assertTrue(method_exists($learningmap->configformclass, 'get_field_definitions'),
            'The course settings form needs field definitions from the learning view.');

        $definitions = $learningmap->configformclass::get_field_definitions($course);
        $this->assertNotEmpty($definitions);
        foreach ($definitions as $name => $definition) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', (string)$name);
            $this->assertArrayHasKey('label', $definition);
            $this->assertNotSame('', trim((string)$definition['label']));
        }
    }

    /**
     * Creates a selfstudy course with one publishable draft course path.
     *
     * @return array [$course, $repository, $pathid]
     */
    private function create_course_with_draft_path(): array {
        set_config('enablecompletion', 1);
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy', 'numsections' => 1, 'enablecompletion' => 1]);
        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Station',
            'completion' => COMPLETION_TRACKING_MANUAL], ['section' => 1]);

        $repository = new \format_selfstudy\local\path_repository();
        $pathid = $repository->create_path((int)$course->id, [
            'name' => 'Hook test path',
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

        return [$course, $repository, $pathid];
    }

    /**
     * Puts the global page into the selfstudy course page state.
     *
     * @param \stdClass $course
     */
    private function prepare_course_page(\stdClass $course): void {
        global $PAGE;

        $this->setAdminUser();
        $PAGE = new \moodle_page();
        $PAGE->set_course($course);
        $PAGE->set_pagetype('course-view-selfstudy');
    }

    /**
     * Builds the hook object the callback receives.
     *
     * @return \core\hook\output\before_http_headers
     */
    private function make_hook(): \core\hook\output\before_http_headers {
        global $PAGE;

        return new \core\hook\output\before_http_headers($PAGE->get_renderer('core'));
    }
}
