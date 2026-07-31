<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for the Learning-Nuggets selfstudy experience.
 *
 * @package    format_selfstudy
 * @category   test
 */
class format_selfstudy_nuggets_experience_test extends advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/../experience/nuggets/classes/inline_content.php');
        require_once(__DIR__ . '/../experience/nuggets/classes/feed_builder.php');
        require_once(__DIR__ . '/../experience/nuggets/classes/card_builder.php');
        require_once(__DIR__ . '/../experience/nuggets/classes/app_renderer.php');
        require_once(__DIR__ . '/../experience/nuggets/classes/renderer.php');
        require_once(__DIR__ . '/../experience/nuggets/classes/config_form.php');
        require_once(__DIR__ . '/../experience/nuggets/classes/experience.php');
    }

    public function test_feed_builder_groups_outline_into_chapters(): void {
        $baseview = (object)[
            'path' => (object)['id' => 1],
            'progress' => (object)['complete' => 1, 'total' => 3, 'percentage' => 33],
            'outline' => [
                $this->milestone('Chapter one'),
                $this->station('Nugget A', 'complete'),
                $this->station('Nugget B', 'open'),
                $this->milestone('Chapter two'),
                $this->station('Nugget C', 'locked'),
            ],
        ];

        $model = (new \selfstudyexperience_nuggets\feed_builder())
            ->build($this->fake_course(), $baseview, (object)[]);

        $this->assertSame(3, $model->nuggetcount);
        $this->assertSame(3, $model->mandatorytotal);
        $this->assertSame(1, $model->mandatorycomplete);
        $this->assertFalse($model->iscomplete);

        $kinds = array_map(static function(\stdClass $entry): string {
            return $entry->kind;
        }, $model->entries);
        $this->assertSame(['chapter', 'nugget', 'nugget', 'chapter', 'nugget'], $kinds);
    }

    public function test_current_nugget_is_first_open_station(): void {
        $baseview = (object)[
            'path' => (object)['id' => 1],
            'progress' => (object)['complete' => 1, 'total' => 3, 'percentage' => 33],
            'outline' => [
                $this->station('Done', 'complete', 11),
                $this->station('Now', 'open', 12),
                $this->station('Later', 'open', 13),
            ],
        ];

        $model = (new \selfstudyexperience_nuggets\feed_builder())
            ->build($this->fake_course(), $baseview, (object)[]);

        $this->assertSame('module-12', $model->currentanchor);
        $nuggets = array_values(array_filter($model->entries, static function(\stdClass $entry): bool {
            return $entry->kind === 'nugget';
        }));
        $this->assertTrue($nuggets[1]->iscurrent);
        $this->assertNotNull($model->recommendednext);
        $this->assertSame('Later', $model->recommendednext->title);
    }

    public function test_locked_visibility_modes(): void {
        $outline = [
            $this->milestone('Open chapter'),
            $this->station('Open nugget', 'open', 21),
            $this->milestone('Locked chapter'),
            $this->station('Locked nugget', 'locked', 22),
        ];
        $baseview = (object)[
            'path' => (object)['id' => 1],
            'progress' => (object)['complete' => 0, 'total' => 2, 'percentage' => 0],
            'outline' => $outline,
        ];
        $builder = new \selfstudyexperience_nuggets\feed_builder();
        $course = $this->fake_course();

        $marked = $builder->build($course, $baseview, (object)['lockedvisibility' => 'marked']);
        $this->assertCount(4, $marked->entries);

        $reduced = (new \selfstudyexperience_nuggets\feed_builder())
            ->build($course, $baseview, (object)['lockedvisibility' => 'reduced']);
        $lockednugget = end($reduced->entries);
        $this->assertTrue(!empty($lockednugget->reduced));

        $hidden = (new \selfstudyexperience_nuggets\feed_builder())
            ->build($course, $baseview, (object)['lockedvisibility' => 'hidden']);
        // The locked nugget and its now-empty chapter both disappear.
        $this->assertCount(2, $hidden->entries);
        // Aggregates still count the hidden mandatory nugget.
        $this->assertSame(2, $hidden->mandatorytotal);
    }

    public function test_renderer_supports_and_renders_feed(): void {
        $course = $this->fake_course();
        $baseview = (object)[
            'path' => (object)['id' => 1],
            'progress' => (object)['complete' => 1, 'total' => 2, 'percentage' => 50],
            'outline' => [
                $this->milestone('Basics'),
                $this->station('Watch the intro', 'complete', 31),
                $this->station('Try it yourself', 'open', 32),
            ],
        ];
        $config = (object)['mode' => 'feed', 'lockedvisibility' => 'marked'];
        $renderer = new \selfstudyexperience_nuggets\renderer();

        $this->assertTrue($renderer->supports($course, $baseview, $config));
        $html = $renderer->render_course_entry($course, $baseview, $config);
        $this->assertStringContainsString('format-selfstudy-nuggets-mode-feed', $html);
        $this->assertStringContainsString('format-selfstudy-nuggets-progressheader', $html);
        $this->assertStringContainsString('Basics', $html);
        $this->assertStringContainsString('Watch the intro', $html);
        $this->assertStringContainsString('id="module-32"', $html);
        $this->assertStringContainsString('format-selfstudy-nuggets-card-current', $html);

        // Scroll navigation below each nugget: the first card only offers
        // "next", the last one only "back" — plus the hero button that also
        // targets the current nugget.
        $this->assertSame(3, substr_count($html, 'data-nugget-nav='));
        $this->assertStringContainsString('data-nugget-nav="module-32"', $html);
        $this->assertStringContainsString('data-nugget-nav="module-31"', $html);

        // The start hero shows the course, the segment strip and a continue
        // button pointing at the current nugget.
        $this->assertStringContainsString('format-selfstudy-nuggets-hero', $html);
        $this->assertStringContainsString('Nugget course', $html);
        $this->assertSame(2, substr_count($html, 'format-selfstudy-nuggets-segment '));
        $this->assertStringContainsString('format-selfstudy-nuggets-herobutton', $html);

        // Focus mode is on by default: every nugget sits on its own slide and
        // the chapter heading lives inside the first nugget's slide.
        $this->assertStringContainsString('format-selfstudy-nuggets-focus', $html);
        $this->assertSame(2, substr_count($html, 'format-selfstudy-nuggets-slide'));
        $this->assertStringContainsString('format-selfstudy-nuggets-chapter', $html);

        $unfocused = $renderer->render_course_entry($course, $baseview,
            (object)['mode' => 'feed', 'lockedvisibility' => 'marked', 'focusmode' => 0]);
        $this->assertStringNotContainsString('format-selfstudy-nuggets-focus', $unfocused);
    }

    public function test_renderer_handles_locked_nugget_without_url(): void {
        $course = $this->fake_course();
        $baseview = (object)[
            'path' => (object)['id' => 1],
            'progress' => (object)['complete' => 0, 'total' => 1, 'percentage' => 0],
            'outline' => [
                (object)['type' => 'station', 'title' => 'Sealed nugget', 'status' => 'locked', 'url' => null],
            ],
        ];

        $html = (new \selfstudyexperience_nuggets\renderer())
            ->render_course_entry($course, $baseview, (object)['lockedvisibility' => 'marked']);
        $this->assertStringContainsString('Sealed nugget', $html);
        $this->assertStringContainsString('format-selfstudy-nuggets-status-locked', $html);
    }

    public function test_renderer_supports_without_path_and_shows_launch(): void {
        // The app content is authored, so the nuggets view is always available
        // and renders a launch card even for a course without a learning path.
        $renderer = new \selfstudyexperience_nuggets\renderer();
        $empty = (object)['path' => null, 'progress' => (object)[], 'outline' => []];
        $course = $this->fake_course();

        $this->assertTrue($renderer->supports($course, $empty, (object)[]));
        $html = $renderer->render_course_entry($course, $empty, (object)[]);
        $this->assertStringContainsString('format-selfstudy-nqlaunch', $html);
        $this->assertStringContainsString('nuggets_app.php', $html);
        // No feed without a path.
        $this->assertStringNotContainsString('format-selfstudy-nuggets-mode-feed', $html);
    }

    public function test_card_builder_derives_nuggets_from_activities(): void {
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(
            ['format' => 'selfstudy', 'numsections' => 2, 'enablecompletion' => 1]);

        // Section 1 = one nugget with two activities = two cards.
        $generator->create_module('page', ['course' => $course->id, 'name' => 'Was ist Tarif?',
            'content' => '<h2>Tarif erklärt</h2><p>Kurz und knapp.</p>', 'contentformat' => FORMAT_HTML],
            ['section' => 1]);
        $generator->create_module('label', ['course' => $course->id,
            'intro' => '<p>Merksatz.</p>', 'introformat' => FORMAT_HTML], ['section' => 1]);

        $builder = new \selfstudyexperience_nuggets\card_builder();
        $this->assertTrue($builder->has_content($course));

        $nuggets = $builder->nuggets($course);
        $this->assertCount(1, $nuggets);
        $nugget = reset($nuggets);
        // cover + 2 activity cards + outro.
        $this->assertCount(4, $nugget->cards);
        $this->assertSame('cover', $nugget->cards[0]->type);
        $this->assertSame('outro', $nugget->cards[3]->type);
        $this->assertStringContainsString('Tarif erklärt', $nugget->cards[1]->html);

        $home = $builder->home($course);
        $this->assertCount(1, $home->topics);
        $this->assertNotNull($home->hero);
    }

    public function test_card_type_override_wins_over_auto(): void {
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(
            ['format' => 'selfstudy', 'numsections' => 1, 'enablecompletion' => 1]);
        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Zahl',
            'content' => '<h1>3,9 Mio.</h1>', 'contentformat' => FORMAT_HTML], ['section' => 1]);

        // Page auto-maps to statement; the override forces a figure card.
        \format_selfstudy_save_cm_metadata((int)$page->cmid, (object)[
            'learninggoal' => '', 'durationminutes' => 3, 'competencies' => '', 'nuggetcardtype' => 'figure']);

        $nuggets = (new \selfstudyexperience_nuggets\card_builder())->nuggets($course);
        $nugget = reset($nuggets);
        $this->assertSame('figure', $nugget->cards[1]->type);
        $this->assertSame('red', $nugget->cards[1]->bg);
    }

    public function test_app_renderer_renders_derived_model(): void {
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(
            ['format' => 'selfstudy', 'numsections' => 1, 'enablecompletion' => 1]);
        $generator->create_module('page', ['course' => $course->id, 'name' => 'Intro',
            'content' => '<p>Hallo.</p>', 'contentformat' => FORMAT_HTML], ['section' => 1]);

        $builder = new \selfstudyexperience_nuggets\card_builder();
        $html = (new \selfstudyexperience_nuggets\app_renderer())->render(
            $builder->home($course), $builder->nuggets($course),
            new \moodle_url('/course/view.php', ['id' => (int)$course->id]));

        $this->assertStringContainsString('nq-app', $html);
        $this->assertStringContainsString('nq-home', $html);
        $this->assertStringContainsString('nq-player', $html);
        // cover + 1 activity card + outro = 3 cards / segments.
        $this->assertSame(3, substr_count($html, 'nq-card '));
        $this->assertSame(3, substr_count($html, 'nq-seg'));
    }

    public function test_app_shows_empty_state_without_activities(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy', 'numsections' => 1]);

        $builder = new \selfstudyexperience_nuggets\card_builder();
        $this->assertFalse($builder->has_content($course));
        $home = $builder->home($course);
        $this->assertEmpty($home->topics);

        $html = (new \selfstudyexperience_nuggets\app_renderer())->render(
            $home, $builder->nuggets($course), new \moodle_url('/course/view.php', ['id' => (int)$course->id]));
        $this->assertStringContainsString('nq-home', $html);
        $this->assertStringNotContainsString('nq-player', $html);
    }

    public function test_optional_nuggets_do_not_block_completion(): void {
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(
            ['format' => 'selfstudy', 'numsections' => 2, 'enablecompletion' => 1]);
        $mandatory = $generator->create_module('page', ['course' => $course->id, 'name' => 'Mandatory',
            'completion' => COMPLETION_TRACKING_MANUAL], ['section' => 1]);
        $optional = $generator->create_module('page', ['course' => $course->id, 'name' => 'Optional extra',
            'completion' => COMPLETION_TRACKING_MANUAL], ['section' => 2]);

        $modinfo = get_fast_modinfo($course);
        $section = $modinfo->get_cm($optional->cmid)->get_section_info();
        course_get_format($course)->update_section_format_options([
            'id' => $section->id,
            'pathkind' => 'optional',
        ]);
        rebuild_course_cache((int)$course->id, true);

        $baseview = (object)[
            'path' => (object)['id' => 1],
            'progress' => (object)['complete' => 1, 'total' => 2, 'percentage' => 50],
            'outline' => [
                (object)['type' => 'station', 'title' => 'Mandatory', 'status' => 'complete',
                    'url' => 'https://example.test/1', 'cmid' => (int)$mandatory->cmid],
                (object)['type' => 'station', 'title' => 'Optional extra', 'status' => 'open',
                    'url' => 'https://example.test/2', 'cmid' => (int)$optional->cmid],
            ],
        ];

        $model = (new \selfstudyexperience_nuggets\feed_builder())->build($course, $baseview, (object)[]);

        $this->assertSame(1, $model->mandatorytotal);
        $this->assertSame(1, $model->mandatorycomplete);
        $this->assertSame(1, $model->optionaltotal);
        $this->assertSame(0, $model->optionalcomplete);
        $this->assertTrue($model->iscomplete, 'Open optional nuggets must not block the 100 percent.');
        $this->assertSame(100, $model->percentage);
    }

    public function test_manual_completion_button_follows_tracking_mode(): void {
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(
            ['format' => 'selfstudy', 'numsections' => 1, 'enablecompletion' => 1]);
        $manual = $generator->create_module('page', ['course' => $course->id, 'name' => 'Manual nugget',
            'completion' => COMPLETION_TRACKING_MANUAL], ['section' => 1]);
        $automatic = $generator->create_module('page', ['course' => $course->id, 'name' => 'Automatic nugget',
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => COMPLETION_VIEW_REQUIRED],
            ['section' => 1]);

        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);

        $baseview = (object)[
            'path' => (object)['id' => 1],
            'progress' => (object)['complete' => 0, 'total' => 2, 'percentage' => 0],
            'outline' => [
                (object)['type' => 'station', 'title' => 'Manual nugget', 'status' => 'open',
                    'url' => 'https://example.test/1', 'cmid' => (int)$manual->cmid],
                (object)['type' => 'station', 'title' => 'Automatic nugget', 'status' => 'open',
                    'url' => 'https://example.test/2', 'cmid' => (int)$automatic->cmid],
            ],
        ];

        $html = (new \selfstudyexperience_nuggets\renderer())
            ->render_course_entry($course, $baseview, (object)['manualcompletion' => 1]);

        $this->assertSame(1, substr_count($html, 'format-selfstudy-nuggets-completionform'),
            'Only the manually tracked nugget offers the toggle.');
        $this->assertStringContainsString('togglecompletion.php', $html);

        $disabled = (new \selfstudyexperience_nuggets\renderer())
            ->render_course_entry($course, $baseview, (object)['manualcompletion' => 0]);
        $this->assertStringNotContainsString('format-selfstudy-nuggets-completionform', $disabled);
    }

    public function test_config_form_defines_expected_fields(): void {
        $fields = \selfstudyexperience_nuggets\config_form::get_field_definitions($this->fake_course());
        $this->assertArrayHasKey('mode', $fields);
        $this->assertSame('select', $fields['mode']['type']);
        $this->assertArrayHasKey('feed', $fields['mode']['options']);
        $this->assertArrayHasKey('navmodel', $fields);
        $this->assertArrayHasKey('snap', $fields['navmodel']['options']);
        $this->assertArrayHasKey('story', $fields['navmodel']['options']);
        $this->assertArrayHasKey('lockedvisibility', $fields);
        $this->assertArrayHasKey('inlineembed', $fields);
        $this->assertArrayHasKey('scrollcompletion', $fields);
        $this->assertArrayHasKey('manualcompletion', $fields);
        $this->assertArrayHasKey('autoscroll', $fields);
    }

    public function test_page_content_renders_inline_in_the_feed(): void {
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(
            ['format' => 'selfstudy', 'numsections' => 1, 'enablecompletion' => 1]);
        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Inline page',
            'content' => '<p>Der ganze Seiteninhalt steht im Feed.</p>', 'contentformat' => FORMAT_HTML,
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => COMPLETION_VIEW_REQUIRED],
            ['section' => 1]);

        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);

        $baseview = (object)[
            'path' => (object)['id' => 1],
            'progress' => (object)['complete' => 0, 'total' => 1, 'percentage' => 0],
            'outline' => [
                (object)['type' => 'station', 'title' => 'Inline page', 'status' => 'open',
                    'url' => 'https://example.test/1', 'cmid' => (int)$page->cmid],
            ],
        ];

        $html = (new \selfstudyexperience_nuggets\renderer())
            ->render_course_entry($course, $baseview, (object)['inlineembed' => 1, 'scrollcompletion' => 1]);
        $this->assertStringContainsString('Der ganze Seiteninhalt steht im Feed.', $html);
        $this->assertStringContainsString('format-selfstudy-nuggets-embed-page', $html);
        $this->assertStringContainsString('data-viewcm="' . (int)$page->cmid . '"', $html,
            'View-completion nuggets carry the marker for scroll completion.');
        $this->assertStringContainsString('format-selfstudy-nuggets-activitylink', $html,
            'Embedded nuggets keep a quiet link to the activity page.');
        $this->assertStringNotContainsString('format-selfstudy-nuggets-openbutton', $html);

        $plain = (new \selfstudyexperience_nuggets\renderer())
            ->render_course_entry($course, $baseview, (object)['inlineembed' => 0]);
        $this->assertStringNotContainsString('format-selfstudy-nuggets-embed-page', $plain);
        $this->assertStringContainsString('format-selfstudy-nuggets-openbutton', $plain);
    }

    public function test_locked_nuggets_never_embed_content(): void {
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(
            ['format' => 'selfstudy', 'numsections' => 1, 'enablecompletion' => 1]);
        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Hidden treasure',
            'content' => '<p>Geheimer Inhalt.</p>', 'contentformat' => FORMAT_HTML], ['section' => 1]);

        $baseview = (object)[
            'path' => (object)['id' => 1],
            'progress' => (object)['complete' => 0, 'total' => 1, 'percentage' => 0],
            'outline' => [
                (object)['type' => 'station', 'title' => 'Hidden treasure', 'status' => 'locked',
                    'url' => null, 'cmid' => (int)$page->cmid],
            ],
        ];

        $html = (new \selfstudyexperience_nuggets\renderer())
            ->render_course_entry($course, $baseview, (object)['inlineembed' => 1]);
        $this->assertStringNotContainsString('Geheimer Inhalt.', $html);
    }

    public function test_registry_discovers_nuggets_experience(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'selfstudy']);
        $registry = new \format_selfstudy\local\experience_registry();

        $components = [];
        foreach ($registry->get_course_experiences($course) as $entry) {
            $components[$entry->component] = $entry;
        }

        $this->assertArrayHasKey('selfstudyexperience_nuggets', $components);
        $nuggets = $components['selfstudyexperience_nuggets'];
        $this->assertFalse((bool)$nuggets->missing);
        $this->assertSame('\\selfstudyexperience_nuggets\\config_form', $nuggets->configformclass);
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
     * @param int $cmid
     * @return \stdClass
     */
    private function station(string $title, string $status, int $cmid = 0): \stdClass {
        return (object)[
            'type' => 'station',
            'title' => $title,
            'status' => $status,
            'url' => 'https://example.test/mod/page/view.php?id=1',
            'cmid' => $cmid,
        ];
    }

    /**
     * Returns a minimal course record for renderer calls.
     *
     * @return \stdClass
     */
    private function fake_course(): \stdClass {
        return (object)['id' => 1, 'fullname' => 'Nugget course'];
    }
}
