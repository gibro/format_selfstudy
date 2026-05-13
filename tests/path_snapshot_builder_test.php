<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for runtime snapshot building.
 *
 * @package    format_selfstudy
 * @category   test
 */
class format_selfstudy_path_snapshot_builder_test extends advanced_testcase {

    public function test_builder_normalises_flat_path_items_to_runtime_snapshot(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy', 'numsections' => 2]);
        $page1 = $generator->create_module('page', ['course' => $course->id, 'name' => 'Pflicht'], ['section' => 1]);
        $page2 = $generator->create_module('page', ['course' => $course->id, 'name' => 'Wahl A'], ['section' => 1]);
        $page3 = $generator->create_module('page', ['course' => $course->id, 'name' => 'Wahl B'], ['section' => 1]);
        $page4 = $generator->create_module('page', ['course' => $course->id, 'name' => 'Praxis'], ['section' => 2]);

        $repository = new \format_selfstudy\local\path_repository();
        $pathid = $repository->create_path((int)$course->id, [
            'name' => 'Snapshot builder path',
            'description' => 'Runtime source',
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
                    'alternativepeers' => ['ms1'],
                ],
            ],
            [
                'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
                'cmid' => (int)$page4->cmid,
            ],
        ]);

        $path = $repository->get_path_with_items($pathid);
        $builder = new \format_selfstudy\local\path_snapshot_builder();
        $snapshot = $builder->build_snapshot($course, $path);
        $decoded = json_decode($snapshot->json, true);

        $this->assertSame(1, $snapshot->schema);
        $this->assertSame(64, strlen($snapshot->sourcehash));
        $this->assertSame(1, $decoded['schema']);
        $this->assertSame($pathid, $decoded['path']['id']);
        $this->assertSame(['milestone-ms1', 'milestone-ms2'], $decoded['root']);

        $nodes = [];
        foreach ($decoded['nodes'] as $node) {
            $nodes[$node['key']] = $node;
        }

        $this->assertSame('Grundlagen', $nodes['milestone-ms1']['title']);
        $this->assertSame([ 'ms2' ], $nodes['milestone-ms1']['alternativepeers']);
        $this->assertSame('station-' . (int)$page1->cmid, $nodes['milestone-ms1']['children'][0]);
        $this->assertSame('Pflicht', $nodes['station-' . (int)$page1->cmid]['title']);

        $alternativekey = $nodes['milestone-ms1']['children'][1];
        $this->assertSame(\format_selfstudy\local\path_repository::ITEM_ALTERNATIVE, $nodes[$alternativekey]['type']);
        $this->assertSame([
            'station-' . (int)$page2->cmid,
            'station-' . (int)$page3->cmid,
        ], $nodes[$alternativekey]['children']);

        $this->assertSame(['station-' . (int)$page4->cmid], $nodes['milestone-ms2']['children']);
    }

    public function test_builder_omits_invalid_and_empty_runtime_nodes(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'selfstudy']);

        $path = (object)[
            'id' => 42,
            'userid' => 0,
            'name' => 'Invalid nodes',
            'description' => '',
            'timemodified' => 1,
            'items' => [
                (object)[
                    'id' => 1,
                    'parentid' => 0,
                    'itemtype' => \format_selfstudy\local\path_repository::ITEM_MILESTONE,
                    'title' => 'Start',
                    'sortorder' => 0,
                    'configdata' => json_encode(['milestonekey' => 'start']),
                ],
                (object)[
                    'id' => 2,
                    'parentid' => 0,
                    'itemtype' => \format_selfstudy\local\path_repository::ITEM_STATION,
                    'cmid' => 0,
                    'sortorder' => 1,
                ],
                (object)[
                    'id' => 3,
                    'parentid' => 0,
                    'itemtype' => \format_selfstudy\local\path_repository::ITEM_ALTERNATIVE,
                    'title' => 'Empty alternative',
                    'sortorder' => 2,
                ],
            ],
        ];

        $builder = new \format_selfstudy\local\path_snapshot_builder();
        $decoded = json_decode($builder->build_snapshot($course, $path)->json, true);

        $this->assertSame(['milestone-start'], $decoded['root']);
        $this->assertCount(1, $decoded['nodes']);
        $this->assertSame([], $decoded['nodes'][0]['children']);
    }
}
