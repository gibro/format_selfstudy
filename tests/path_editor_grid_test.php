<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for the learning path editor grid model.
 *
 * @package    format_selfstudy
 * @category   test
 */
class format_selfstudy_path_editor_grid_test extends advanced_testcase {

    /**
     * Loads the editor helper functions without executing the page controller.
     */
    protected function setUp(): void {
        parent::setUp();
        global $FORMAT_SELFSTUDY_PATH_EDITOR_FUNCTIONS_ONLY;
        $FORMAT_SELFSTUDY_PATH_EDITOR_FUNCTIONS_ONLY = true;
        require_once(__DIR__ . '/../path_editor.php');
    }

    /**
     * Returns a minimal editable activity record.
     *
     * @param int $id
     * @param int $sectionnum
     * @param string $sectionname
     * @return stdClass
     */
    private function activity(int $id, int $sectionnum, string $sectionname): stdClass {
        return (object)[
            'id' => $id,
            'name' => 'Activity ' . $id,
            'sectionnum' => $sectionnum,
            'sectionname' => $sectionname,
        ];
    }

    /**
     * Returns minimal section records keyed like the editor receives them.
     *
     * @return stdClass[]
     */
    private function sections(): array {
        return [
            1 => (object)['sectionnum' => 1, 'name' => 'Grundlagen'],
            2 => (object)['sectionnum' => 2, 'name' => 'Praxis'],
        ];
    }

    public function test_grid_json_builds_milestones_required_steps_and_alternatives(): void {
        $activities = [
            $this->activity(11, 1, 'Grundlagen'),
            $this->activity(12, 1, 'Grundlagen'),
            $this->activity(21, 2, 'Praxis'),
            $this->activity(22, 2, 'Praxis'),
        ];
        $grid = [
            'milestones' => [
                [
                    'key' => 'ms1',
                    'sectionnum' => 1,
                    'alternativepeers' => ['ms2'],
                    'rows' => [
                        [['cmid' => 11]],
                        [['cmid' => 12], ['cmid' => 21]],
                        [['cmid' => 11], ['cmid' => 999]],
                    ],
                ],
                [
                    'key' => 'ms2',
                    'sectionnum' => 2,
                    'alternativepeers' => ['ms1'],
                    'rows' => [
                        [['cmid' => 22]],
                    ],
                ],
            ],
        ];

        $items = format_selfstudy_path_editor_build_items_from_grid(json_encode($grid), $activities, $this->sections());

        $this->assertCount(5, $items);
        $this->assertSame(\format_selfstudy\local\path_repository::ITEM_MILESTONE, $items[0]['itemtype']);
        $this->assertSame('Grundlagen', $items[0]['title']);
        $this->assertSame(['ms2'], $items[0]['configdata']['alternativepeers']);
        $this->assertSame(\format_selfstudy\local\path_repository::ITEM_STATION, $items[1]['itemtype']);
        $this->assertSame(11, $items[1]['cmid']);
        $this->assertSame(\format_selfstudy\local\path_repository::ITEM_ALTERNATIVE, $items[2]['itemtype']);
        $this->assertSame([12, 21], array_column($items[2]['children'], 'cmid'));
        $this->assertSame(\format_selfstudy\local\path_repository::ITEM_MILESTONE, $items[3]['itemtype']);
        $this->assertSame('Praxis', $items[3]['title']);
        $this->assertSame(22, $items[4]['cmid']);
    }

    public function test_path_items_roundtrip_back_to_grid_rows(): void {
        $activities = [
            $this->activity(11, 1, 'Grundlagen'),
            $this->activity(12, 1, 'Grundlagen'),
            $this->activity(21, 2, 'Praxis'),
            $this->activity(22, 2, 'Praxis'),
        ];
        $path = (object)[
            'items' => [
                (object)[
                    'id' => 1,
                    'parentid' => 0,
                    'itemtype' => \format_selfstudy\local\path_repository::ITEM_MILESTONE,
                    'title' => 'Old title',
                    'description' => '',
                    'sortorder' => 0,
                    'configdata' => json_encode([
                        'milestonekey' => 'ms1',
                        'sectionnum' => 1,
                        'alternativepeers' => ['ms2'],
                    ]),
                ],
                (object)[
                    'id' => 2,
                    'parentid' => 0,
                    'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
                    'cmid' => 11,
                    'sortorder' => 1,
                ],
                (object)[
                    'id' => 3,
                    'parentid' => 0,
                    'itemtype' => \format_selfstudy\local\path_repository::ITEM_ALTERNATIVE,
                    'title' => '',
                    'sortorder' => 2,
                ],
                (object)[
                    'id' => 4,
                    'parentid' => 3,
                    'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
                    'cmid' => 12,
                    'sortorder' => 0,
                ],
                (object)[
                    'id' => 5,
                    'parentid' => 3,
                    'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
                    'cmid' => 21,
                    'sortorder' => 1,
                ],
                (object)[
                    'id' => 6,
                    'parentid' => 0,
                    'itemtype' => \format_selfstudy\local\path_repository::ITEM_MILESTONE,
                    'title' => 'Old title',
                    'description' => '',
                    'sortorder' => 3,
                    'configdata' => json_encode([
                        'milestonekey' => 'ms2',
                        'sectionnum' => 2,
                        'alternativepeers' => ['ms1'],
                    ]),
                ],
                (object)[
                    'id' => 7,
                    'parentid' => 0,
                    'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
                    'cmid' => 22,
                    'sortorder' => 4,
                ],
            ],
        ];

        $grid = format_selfstudy_path_editor_grid_from_path($path, $activities, $this->sections());

        $this->assertCount(2, $grid['milestones']);
        $this->assertSame('Grundlagen', $grid['milestones'][0]['title']);
        $this->assertSame('Praxis', $grid['milestones'][1]['title']);
        $this->assertSame([[['cmid' => 11]], [['cmid' => 12], ['cmid' => 21]]], $grid['milestones'][0]['rows']);
        $this->assertSame([[['cmid' => 22]]], $grid['milestones'][1]['rows']);
        $this->assertSame(['ms2'], $grid['milestones'][0]['alternativepeers']);
        $this->assertSame(['ms1'], $grid['milestones'][1]['alternativepeers']);
    }

    public function test_missing_or_general_section_activities_are_reported_and_removed_from_saved_items(): void {
        $activities = [
            $this->activity(11, 1, 'Grundlagen'),
            $this->activity(12, 1, 'Grundlagen'),
        ];
        $path = (object)[
            'items' => [
                (object)[
                    'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
                    'cmid' => 11,
                ],
                (object)[
                    'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
                    'cmid' => 99,
                ],
                (object)[
                    'itemtype' => \format_selfstudy\local\path_repository::ITEM_ALTERNATIVE,
                ],
                (object)[
                    'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
                    'cmid' => 100,
                ],
            ],
        ];
        $grid = [
            'milestones' => [[
                'key' => 'ms1',
                'sectionnum' => 1,
                'rows' => [
                    [['cmid' => 11], ['cmid' => 99]],
                    [['cmid' => 12], ['cmid' => 100]],
                ],
            ]],
        ];

        $this->assertSame([99, 100], format_selfstudy_path_editor_get_missing_path_cmids($path, $activities));
        $this->assertSame([11, 99, 12, 100], format_selfstudy_path_editor_get_grid_cmids($grid));

        $items = format_selfstudy_path_editor_build_items_from_grid(json_encode($grid), $activities, $this->sections());

        $this->assertCount(3, $items);
        $this->assertSame(\format_selfstudy\local\path_repository::ITEM_MILESTONE, $items[0]['itemtype']);
        $this->assertSame(11, $items[1]['cmid']);
        $this->assertSame(12, $items[2]['cmid']);
    }

    public function test_publish_validation_reports_missing_duplicate_empty_and_completion_issues(): void {
        $activitywithoutcompletion = $this->activity(11, 1, 'Grundlagen');
        $activitywithoutcompletion->hascompletion = false;
        $activitywithcompletion = $this->activity(12, 1, 'Grundlagen');
        $activitywithcompletion->hascompletion = true;
        $grid = [
            'milestones' => [
                [
                    'key' => 'ms1',
                    'sectionnum' => 1,
                    'rows' => [
                        [['cmid' => 11]],
                        [['cmid' => 12], ['cmid' => 99]],
                        [['cmid' => 12]],
                    ],
                ],
                [
                    'key' => 'ms2',
                    'sectionnum' => 2,
                    'rows' => [],
                ],
            ],
        ];

        $errors = format_selfstudy_path_editor_validate_grid_for_publish(json_encode($grid),
            [$activitywithoutcompletion, $activitywithcompletion], $this->sections());

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('CM 99', implode("\n", $errors));
        $this->assertStringContainsString('Activity 12', implode("\n", $errors));
        $this->assertStringContainsString('Activity 11', implode("\n", $errors));
        $this->assertStringContainsString('Praxis', implode("\n", $errors));
    }

    public function test_milestone_alternatives_are_normalised_from_legacy_groups_and_peers(): void {
        $grid = [
            'milestones' => [
                [
                    'key' => 'ms1',
                    'alternativegroup' => 'group-a',
                    'alternativepeers' => ['missing'],
                    'rows' => [],
                ],
                [
                    'key' => 'ms2',
                    'alternativegroup' => 'group-a',
                    'alternativepeers' => [],
                    'rows' => [],
                ],
                [
                    'key' => 'ms3',
                    'alternativegroup' => '',
                    'alternativepeers' => ['ms1'],
                    'rows' => [],
                ],
                [
                    'key' => 'ms3',
                    'alternativegroup' => '',
                    'alternativepeers' => [],
                    'rows' => [],
                ],
            ],
        ];

        $normalised = format_selfstudy_path_editor_normalise_milestone_alternatives($grid);

        $this->assertSame('ms1', $normalised['milestones'][0]['key']);
        $this->assertSame('ms2', $normalised['milestones'][1]['key']);
        $this->assertSame('ms3', $normalised['milestones'][2]['key']);
        $this->assertSame('milestone-4', $normalised['milestones'][3]['key']);
        $this->assertEqualsCanonicalizing(['ms2', 'ms3'], $normalised['milestones'][0]['alternativepeers']);
        $this->assertSame(['ms1'], $normalised['milestones'][1]['alternativepeers']);
        $this->assertSame(['ms1'], $normalised['milestones'][2]['alternativepeers']);
        $this->assertSame([], $normalised['milestones'][3]['alternativepeers']);
    }
}
