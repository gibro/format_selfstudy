<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for compiling selfstudy rules to Moodle availability.
 *
 * @package    format_selfstudy
 * @category   test
 */
class format_selfstudy_availability_compiler_test extends advanced_testcase {

    public function test_completion_rule_compiles_to_moodle_availability(): void {
        $compiler = new \format_selfstudy\local\availability_compiler();
        $result = $compiler->compile([
            'type' => 'completion',
            'cmid' => 42,
        ]);
        $json = json_decode($result->json, true);

        $this->assertTrue($result->available);
        $this->assertSame('&', $json['op']);
        $this->assertSame(42, $json['c'][0]['cm']);
        $this->assertSame(COMPLETION_COMPLETE, $json['c'][0]['e']);
        $this->assertSame(1, $result->complexity);
    }

    public function test_all_of_and_any_of_compile_to_moodle_groups(): void {
        $compiler = new \format_selfstudy\local\availability_compiler();

        $all = json_decode($compiler->compile([
            'type' => 'all_of',
            'rules' => [
                ['type' => 'completion', 'cmid' => 1],
                ['type' => 'completion', 'cmid' => 2],
            ],
        ])->json, true);
        $any = json_decode($compiler->compile([
            'type' => 'any_of',
            'rules' => [
                ['type' => 'completion', 'cmid' => 3],
                ['type' => 'completion', 'cmid' => 4],
            ],
        ])->json, true);

        $this->assertSame('&', $all['op']);
        $this->assertSame([1, 2], [$all['c'][0]['cm'], $all['c'][1]['cm']]);
        $this->assertSame('|', $any['op']);
        $this->assertSame([3, 4], [$any['c'][0]['cm'], $any['c'][1]['cm']]);
    }

    public function test_min_count_two_of_three_expands_to_three_combinations(): void {
        $compiler = new \format_selfstudy\local\availability_compiler();
        $result = $compiler->compile([
            'type' => 'min_count',
            'min' => 2,
            'rules' => [
                ['type' => 'completion', 'cmid' => 10],
                ['type' => 'completion', 'cmid' => 11],
                ['type' => 'completion', 'cmid' => 12],
            ],
        ]);
        $json = json_decode($result->json, true);

        $this->assertTrue($result->available);
        $this->assertSame('|', $json['op']);
        $this->assertCount(3, $json['c']);
        $this->assertSame('&', $json['c'][0]['op']);
        $this->assertSame([10, 11], [$json['c'][0]['c'][0]['cm'], $json['c'][0]['c'][1]['cm']]);
    }

    public function test_min_count_over_limit_is_blocked(): void {
        $compiler = new \format_selfstudy\local\availability_compiler();
        $result = $compiler->compile([
            'type' => 'min_count',
            'min' => 3,
            'rules' => [
                ['type' => 'completion', 'cmid' => 1],
                ['type' => 'completion', 'cmid' => 2],
                ['type' => 'completion', 'cmid' => 3],
                ['type' => 'completion', 'cmid' => 4],
                ['type' => 'completion', 'cmid' => 5],
                ['type' => 'completion', 'cmid' => 6],
                ['type' => 'completion', 'cmid' => 7],
            ],
        ]);

        $this->assertFalse($result->available);
        $this->assertSame('', $result->json);
        $this->assertNotEmpty($result->errors);
    }
}
