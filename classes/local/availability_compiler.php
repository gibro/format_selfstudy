<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Compiles selfstudy rule nodes to Moodle core availability JSON.
 */
class availability_compiler {

    /** @var availability_complexity */
    private $complexity;

    /**
     * Constructor.
     *
     * @param availability_complexity|null $complexity
     */
    public function __construct(?availability_complexity $complexity = null) {
        $this->complexity = $complexity ?? new availability_complexity();
    }

    /**
     * Compiles one rule node.
     *
     * @param array $rule
     * @return \stdClass
     */
    public function compile(array $rule): \stdClass {
        $errors = [];
        $warnings = [];
        $complexity = 0;
        $node = $this->compile_node($rule, 1, $errors, $warnings, $complexity);

        return (object)[
            'available' => empty($errors) && $node !== null,
            'json' => empty($errors) && $node !== null ? json_encode($this->normalise_root($node)) : '',
            'warnings' => $warnings,
            'errors' => $errors,
            'complexity' => $complexity,
        ];
    }

    /**
     * Compiles one internal rule node.
     *
     * @param array $rule
     * @param int $depth
     * @param string[] $errors
     * @param string[] $warnings
     * @param int $complexity
     * @return array|null
     */
    private function compile_node(array $rule, int $depth, array &$errors, array &$warnings, int &$complexity): ?array {
        if ($depth > availability_complexity::MAX_DEPTH) {
            $errors[] = get_string('learningpathsynccompiledepthlimit', 'format_selfstudy',
                availability_complexity::MAX_DEPTH);
            return null;
        }

        $type = (string)($rule['type'] ?? '');
        if ($type === 'completion') {
            $cmid = (int)($rule['cmid'] ?? 0);
            if ($cmid <= 0) {
                $errors[] = get_string('learningpathsynccompilemissingcmid', 'format_selfstudy');
                return null;
            }
            $complexity++;
            return [
                'type' => 'completion',
                'cm' => $cmid,
                'e' => COMPLETION_COMPLETE,
            ];
        }

        if ($type === 'all_of' || $type === 'any_of') {
            return $this->compile_group($rule, $type === 'any_of' ? '|' : '&', $depth, $errors, $warnings, $complexity);
        }

        if ($type === 'min_count') {
            return $this->compile_min_count($rule, $depth, $errors, $warnings, $complexity);
        }

        $errors[] = get_string('learningpathsynccompileunknownrule', 'format_selfstudy', $type === '' ? '?' : $type);
        return null;
    }

    /**
     * Compiles an all_of or any_of node.
     *
     * @param array $rule
     * @param string $operator
     * @param int $depth
     * @param string[] $errors
     * @param string[] $warnings
     * @param int $complexity
     * @return array|null
     */
    private function compile_group(array $rule, string $operator, int $depth, array &$errors, array &$warnings,
            int &$complexity): ?array {
        $rules = $this->normalise_rules($rule['rules'] ?? []);
        if (!$rules) {
            $errors[] = get_string('learningpathsynccompileemptygroup', 'format_selfstudy');
            return null;
        }
        if ($operator === '|' && count($rules) > availability_complexity::MAX_ANY_OF_RULES) {
            $errors[] = get_string('learningpathsynccompileanylimit', 'format_selfstudy',
                availability_complexity::MAX_ANY_OF_RULES);
            return null;
        }

        $conditions = [];
        foreach ($rules as $childrule) {
            $child = $this->compile_node($childrule, $depth + 1, $errors, $warnings, $complexity);
            if ($child !== null) {
                $conditions[] = $child;
            }
        }

        if (!$conditions) {
            return null;
        }
        if (count($conditions) === 1) {
            return $conditions[0];
        }

        return [
            'op' => $operator,
            'c' => $conditions,
            'showc' => array_fill(0, count($conditions), true),
            'show' => true,
        ];
    }

    /**
     * Compiles min_count by expanding it to combinations.
     *
     * @param array $rule
     * @param int $depth
     * @param string[] $errors
     * @param string[] $warnings
     * @param int $complexity
     * @return array|null
     */
    private function compile_min_count(array $rule, int $depth, array &$errors, array &$warnings, int &$complexity): ?array {
        $rules = $this->normalise_rules($rule['rules'] ?? []);
        $min = (int)($rule['min'] ?? 0);
        if (!$rules || $min <= 0 || $min > count($rules)) {
            $errors[] = get_string('learningpathsynccompileinvalidmincount', 'format_selfstudy');
            return null;
        }
        if (count($rules) > availability_complexity::MAX_MIN_COUNT_RULES) {
            $errors[] = get_string('learningpathsynccompileminsourcelimit', 'format_selfstudy',
                availability_complexity::MAX_MIN_COUNT_RULES);
            return null;
        }

        $combinationcount = $this->complexity->count_combinations(count($rules), $min);
        if ($combinationcount > availability_complexity::MAX_MIN_COUNT_COMBINATIONS) {
            $errors[] = get_string('learningpathsynccompilecombinationlimit', 'format_selfstudy',
                availability_complexity::MAX_MIN_COUNT_COMBINATIONS);
            return null;
        }

        if ($min === 1) {
            return $this->compile_group(['type' => 'any_of', 'rules' => $rules], '|', $depth, $errors, $warnings,
                $complexity);
        }
        if ($min === count($rules)) {
            return $this->compile_group(['type' => 'all_of', 'rules' => $rules], '&', $depth, $errors, $warnings,
                $complexity);
        }

        $expanded = [];
        foreach ($this->complexity->combinations($rules, $min) as $combination) {
            $expanded[] = [
                'type' => 'all_of',
                'rules' => $combination,
            ];
        }

        return $this->compile_group(['type' => 'any_of', 'rules' => $expanded], '|', $depth, $errors, $warnings,
            $complexity);
    }

    /**
     * Returns a normalised list of child rule arrays.
     *
     * @param mixed $rules
     * @return array
     */
    private function normalise_rules($rules): array {
        if (!is_array($rules)) {
            return [];
        }

        return array_values(array_filter($rules, static function($rule): bool {
            return is_array($rule);
        }));
    }

    /**
     * Ensures Moodle root availability defaults exist.
     *
     * @param array $node
     * @return array
     */
    private function normalise_root(array $node): array {
        if (isset($node['c']) && is_array($node['c'])) {
            $node['show'] = $node['show'] ?? true;
            $node['showc'] = $node['showc'] ?? array_fill(0, count($node['c']), true);
            return $node;
        }

        return [
            'op' => '&',
            'c' => [$node],
            'showc' => [true],
            'show' => true,
        ];
    }
}
