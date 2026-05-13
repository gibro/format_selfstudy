<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for building availability rules from runtime snapshots.
 *
 * @package    format_selfstudy
 * @category   test
 */
class format_selfstudy_availability_rule_builder_test extends advanced_testcase {

    public function test_builder_creates_rules_for_sequence_and_alternative(): void {
        $snapshot = [
            'schema' => 1,
            'nodes' => [
                [
                    'key' => 'milestone-start',
                    'type' => \format_selfstudy\local\path_repository::ITEM_MILESTONE,
                    'title' => 'Start',
                    'children' => ['station-1', 'alternative-1', 'station-4'],
                    'alternativepeers' => [],
                ],
                [
                    'key' => 'station-1',
                    'type' => \format_selfstudy\local\path_repository::ITEM_STATION,
                    'cmid' => 1,
                    'title' => 'A',
                ],
                [
                    'key' => 'alternative-1',
                    'type' => \format_selfstudy\local\path_repository::ITEM_ALTERNATIVE,
                    'title' => 'B oder C',
                    'children' => ['station-2', 'station-3'],
                ],
                [
                    'key' => 'station-2',
                    'type' => \format_selfstudy\local\path_repository::ITEM_STATION,
                    'cmid' => 2,
                    'title' => 'B',
                ],
                [
                    'key' => 'station-3',
                    'type' => \format_selfstudy\local\path_repository::ITEM_STATION,
                    'cmid' => 3,
                    'title' => 'C',
                ],
                [
                    'key' => 'station-4',
                    'type' => \format_selfstudy\local\path_repository::ITEM_STATION,
                    'cmid' => 4,
                    'title' => 'D',
                ],
            ],
            'root' => ['milestone-start'],
            'rules' => [],
        ];

        $rules = (new \format_selfstudy\local\availability_rule_builder())->build_rules($snapshot);

        $this->assertCount(3, $rules);
        $this->assertSame(2, $rules[0]->targetcmid);
        $this->assertSame(3, $rules[1]->targetcmid);
        $this->assertSame(['station-1'], $rules[0]->sourcekeys);
        $this->assertSame('completion', $rules[0]->rule['type']);
        $this->assertSame(4, $rules[2]->targetcmid);
        $this->assertSame('any_of', $rules[2]->rule['type']);
        $this->assertSame(['station-2', 'station-3'], $rules[2]->sourcekeys);
    }
}
