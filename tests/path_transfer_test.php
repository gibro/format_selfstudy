<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for learning path import/export.
 *
 * @package    format_selfstudy
 * @category   test
 */
class format_selfstudy_path_transfer_test extends advanced_testcase {

    public function test_export_and_import_preserve_grid_sections_and_alternative_milestones(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy', 'numsections' => 2]);
        $page1 = $generator->create_module('page', ['course' => $course->id, 'name' => 'Pflicht 1'], ['section' => 1]);
        $page2 = $generator->create_module('page', ['course' => $course->id, 'name' => 'Wahl A'], ['section' => 1]);
        $page3 = $generator->create_module('page', ['course' => $course->id, 'name' => 'Wahl B'], ['section' => 1]);
        $page4 = $generator->create_module('page', ['course' => $course->id, 'name' => 'Praxis'], ['section' => 2]);

        $repository = new \format_selfstudy\local\path_repository();
        $pathid = $repository->create_path((int)$course->id, [
            'name' => 'Transferpfad',
            'description' => 'Export Import',
            'enabled' => 1,
            'userid' => 0,
        ]);
        $repository->replace_path_items($pathid, [
            [
                'itemtype' => \format_selfstudy\local\path_repository::ITEM_MILESTONE,
                'title' => 'Grundlagen',
                'configdata' => [
                    'milestonekey' => 'ms1',
                    'sectionnum' => 1,
                    'alternativegroup' => 'legacy-a',
                    'alternativepeers' => ['ms2'],
                ],
            ],
            [
                'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
                'cmid' => (int)$page1->cmid,
            ],
            [
                'itemtype' => \format_selfstudy\local\path_repository::ITEM_ALTERNATIVE,
                'title' => 'Alternative',
                'children' => [
                    [
                        'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
                        'cmid' => (int)$page2->cmid,
                    ],
                    [
                        'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
                        'cmid' => (int)$page3->cmid,
                    ],
                ],
            ],
            [
                'itemtype' => \format_selfstudy\local\path_repository::ITEM_MILESTONE,
                'title' => 'Praxis',
                'configdata' => [
                    'milestonekey' => 'ms2',
                    'sectionnum' => 2,
                    'alternativegroup' => 'legacy-a',
                    'alternativepeers' => ['ms1'],
                ],
            ],
            [
                'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
                'cmid' => (int)$page4->cmid,
            ],
        ]);

        $transfer = new \format_selfstudy\local\path_transfer($repository);
        $payload = $transfer->export_path($course, $pathid);

        $this->assertSame('format_selfstudy_path', $payload['schema']);
        $this->assertSame(3, $payload['version']);
        $this->assertCount(2, $payload['path']['grid']['milestones']);
        $this->assertSame('legacy-a', $payload['path']['grid']['milestones'][0]['alternativegroup']);
        $this->assertSame(['ms2'], $payload['path']['grid']['milestones'][0]['alternativepeers']);
        $this->assertSame(1, $payload['path']['grid']['milestones'][0]['sectionnum']);
        $this->assertCount(2, $payload['path']['grid']['milestones'][0]['rows']);
        $this->assertCount(2, $payload['path']['grid']['milestones'][0]['rows'][1]);

        $importedpathid = $transfer->import_path($course, $payload);
        $imported = $repository->get_path_tree($importedpathid);

        $this->assertNotEquals($pathid, $importedpathid);
        $this->assertSame('Transferpfad (' . get_string('learningpathimported', 'format_selfstudy') . ')',
            $DB->get_field('format_selfstudy_paths', 'name', ['id' => $importedpathid]));
        $this->assertCount(5, $imported);
        $this->assertSame(\format_selfstudy\local\path_repository::ITEM_MILESTONE, $imported[0]->itemtype);
        $config = json_decode($imported[0]->configdata, true);
        $this->assertSame('ms1', $config['milestonekey']);
        $this->assertSame(1, $config['sectionnum']);
        $this->assertSame('legacy-a', $config['alternativegroup']);
        $this->assertSame(['ms2'], $config['alternativepeers']);
        $this->assertSame((int)$page1->cmid, (int)$imported[1]->cmid);
        $this->assertSame(\format_selfstudy\local\path_repository::ITEM_ALTERNATIVE, $imported[2]->itemtype);
        $this->assertSame([(int)$page2->cmid, (int)$page3->cmid],
            array_map(static function(stdClass $item): int {
                return (int)$item->cmid;
            }, $imported[2]->children));
        $this->assertSame((int)$page4->cmid, (int)$imported[4]->cmid);
    }

    public function test_import_blocks_missing_activity_reference(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy', 'numsections' => 1]);
        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Known page'], ['section' => 1]);

        $repository = new \format_selfstudy\local\path_repository();
        $pathid = $repository->create_path((int)$course->id, [
            'name' => 'Transferpfad',
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

        $transfer = new \format_selfstudy\local\path_transfer($repository);
        $payload = $transfer->export_path($course, $pathid);
        $payload['path']['grid']['milestones'][0]['rows'][0][0]['cmref']['name'] = 'Missing page';
        $payload['path']['grid']['milestones'][0]['rows'][0][0]['cmref']['oldcmid'] = 999999;

        try {
            $transfer->import_path($course, $payload);
            $this->fail('Import with a missing activity reference should be blocked.');
        } catch (moodle_exception $exception) {
            $this->assertSame('learningpathimportactivityresolutionfailed', $exception->errorcode);
        }
    }

    public function test_import_blocks_ambiguous_activity_reference(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy', 'numsections' => 1]);
        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Duplicate'], ['section' => 1]);
        $generator->create_module('page', ['course' => $course->id, 'name' => 'Duplicate'], ['section' => 1]);

        $repository = new \format_selfstudy\local\path_repository();
        $pathid = $repository->create_path((int)$course->id, [
            'name' => 'Transferpfad',
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

        $transfer = new \format_selfstudy\local\path_transfer($repository);
        $payload = $transfer->export_path($course, $pathid);
        $payload['path']['grid']['milestones'][0]['rows'][0][0]['cmref']['oldcmid'] = 999999;

        try {
            $transfer->import_path($course, $payload);
            $this->fail('Import with an ambiguous activity reference should be blocked.');
        } catch (moodle_exception $exception) {
            $this->assertSame('learningpathimportactivityresolutionfailed', $exception->errorcode);
        }
    }
}
