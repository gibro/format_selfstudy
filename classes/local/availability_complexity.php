<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Encapsulates technical limits for compiling selfstudy rules to Moodle availability.
 */
class availability_complexity {

    /** @var int Maximum alternatives in one any_of node. */
    public const MAX_ANY_OF_RULES = 8;

    /** @var int Maximum source rules in one min_count node. */
    public const MAX_MIN_COUNT_RULES = 6;

    /** @var int Maximum generated combinations for one min_count node. */
    public const MAX_MIN_COUNT_COMBINATIONS = 20;

    /** @var int Maximum nested rule depth. */
    public const MAX_DEPTH = 4;

    /**
     * Returns the number of k-combinations from n items.
     *
     * @param int $n
     * @param int $k
     * @return int
     */
    public function count_combinations(int $n, int $k): int {
        if ($k < 0 || $n < 0 || $k > $n) {
            return 0;
        }
        if ($k === 0 || $k === $n) {
            return 1;
        }

        $k = min($k, $n - $k);
        $result = 1;
        for ($i = 1; $i <= $k; $i++) {
            $result = (int)(($result * ($n - $k + $i)) / $i);
        }

        return $result;
    }

    /**
     * Builds all k-combinations from a list of rules.
     *
     * @param array $rules
     * @param int $k
     * @return array
     */
    public function combinations(array $rules, int $k): array {
        $rules = array_values($rules);
        if ($k <= 0 || $k > count($rules)) {
            return [];
        }
        if ($k === 1) {
            return array_map(static function($rule): array {
                return [$rule];
            }, $rules);
        }

        $result = [];
        $this->append_combinations($rules, $k, 0, [], $result);
        return $result;
    }

    /**
     * Recursively appends k-combinations.
     *
     * @param array $rules
     * @param int $k
     * @param int $offset
     * @param array $current
     * @param array $result
     */
    private function append_combinations(array $rules, int $k, int $offset, array $current, array &$result): void {
        if (count($current) === $k) {
            $result[] = $current;
            return;
        }

        $remaining = $k - count($current);
        for ($index = $offset; $index <= count($rules) - $remaining; $index++) {
            $next = $current;
            $next[] = $rules[$index];
            $this->append_combinations($rules, $k, $index + 1, $next, $result);
        }
    }
}
