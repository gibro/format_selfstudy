<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Diagnoses and prepares Moodle availability rules for selfstudy learning paths.
 */
class path_sync {

    /** @var path_repository */
    private $repository;

    /**
     * Constructor.
     *
     * @param path_repository|null $repository
     */
    public function __construct(?path_repository $repository = null) {
        $this->repository = $repository ?: new path_repository();
    }

    /**
     * Builds a read-only diagnosis for the selected learning path.
     *
     * @param \stdClass $course
     * @param int $pathid
     * @return \stdClass
     */
    public function diagnose(\stdClass $course, int $pathid): \stdClass {
        $path = $this->repository->get_path_with_items($pathid);
        if (!$path || (int)$path->courseid !== (int)$course->id) {
            throw new \coding_exception('Invalid selfstudy path for course.');
        }

        $modinfo = get_fast_modinfo($course);
        $completion = new \completion_info($course);
        $cminfo = $this->get_course_module_diagnosis($course, $modinfo, $completion);
        $items = $this->prepare_items($path, $modinfo, $completion, $cminfo);
        $rules = $this->preview_rules($items);
        $repairablecompletionmissing = $this->collect_missing_completion_source_items($rules);

        return (object)[
            'path' => $path,
            'activities' => $cminfo,
            'items' => $items,
            'completionenabled' => array_values(array_filter($cminfo, static function(\stdClass $activity): bool {
                return !empty($activity->completionenabled);
            })),
            'completionmissing' => array_values(array_filter($cminfo, static function(\stdClass $activity): bool {
                return empty($activity->completionenabled);
            })),
            'passinggrade' => array_values(array_filter($cminfo, static function(\stdClass $activity): bool {
                return !empty($activity->supportsgradepass);
            })),
            'existingavailability' => array_values(array_filter($cminfo, static function(\stdClass $activity): bool {
                return !empty($activity->availability);
            })),
            'invalidcompletionavailability' => array_values(array_filter($cminfo, static function(\stdClass $activity): bool {
                return !empty($activity->invalidcompletionavailability);
            })),
            'invalidstructureavailability' => array_values(array_filter($cminfo, static function(\stdClass $activity): bool {
                return !empty($activity->invalidstructureavailability);
            })),
            'rules' => $rules,
            'repairablecompletionmissing' => $repairablecompletionmissing,
            'untranslatable' => array_values(array_filter($rules, static function(\stdClass $rule): bool {
                return empty($rule->translatable);
            })),
        ];
    }

    /**
     * Writes translatable learning path rules into Moodle core availability.
     *
     * Existing completion availability conditions on the target activity are
     * replaced by the learning path condition. Other availability conditions are
     * preserved and combined with the path rule using AND.
     *
     * @param \stdClass $course
     * @param int $pathid
     * @return \stdClass
     */
    public function sync(\stdClass $course, int $pathid): \stdClass {
        global $DB;

        $this->repair_path_issues($course, $pathid);
        $diagnosis = $this->diagnose($course, $pathid);
        $result = (object)[
            'written' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        $transaction = $DB->start_delegated_transaction();
        foreach ($diagnosis->rules as $rule) {
            if (empty($rule->translatable) || empty($rule->availabilityjson) || empty($rule->target->cmid)) {
                $result->skipped++;
                if (!empty($rule->reason)) {
                    $result->errors[] = $rule->target->label . ': ' . $rule->reason;
                }
                continue;
            }

            $availability = $this->merge_with_existing_availability(
                $rule->existingtargetavailability,
                $rule->availabilityjson
            );
            if ($availability === false) {
                $result->skipped++;
                $result->errors[] = get_string('learningpathsyncskippedinvalidavailability', 'format_selfstudy',
                    $rule->target->label);
                continue;
            }

            $DB->set_field('course_modules', 'availability', $availability ?: null, ['id' => (int)$rule->target->cmid]);
            $result->written++;
        }
        $transaction->allow_commit();

        if ($result->written > 0) {
            rebuild_course_cache((int)$course->id, true);
        }

        return $result;
    }

    /**
     * Repairs all safely inferable learning path issues.
     *
     * @param \stdClass $course
     * @param int $pathid
     * @return \stdClass
     */
    public function repair_path_issues(\stdClass $course, int $pathid): \stdClass {
        $completion = $this->repair_missing_completion_sources($course, $pathid);
        $availability = $this->cleanup_invalid_availability($course, $pathid);

        return (object)[
            'fixed' => (int)$completion->fixed + (int)$availability->fixed,
            'skipped' => (int)$completion->skipped + (int)$availability->skipped,
            'completionfixed' => (int)$completion->fixed,
            'availabilityfixed' => (int)$availability->fixed,
            'errors' => array_values(array_merge($completion->errors, $availability->errors)),
        ];
    }

    /**
     * Enables a simple completion rule for path source activities that need one.
     *
     * @param \stdClass $course
     * @param int $pathid
     * @return \stdClass
     */
    public function repair_missing_completion_sources(\stdClass $course, int $pathid): \stdClass {
        global $DB;

        $diagnosis = $this->diagnose($course, $pathid);
        $result = (object)[
            'fixed' => 0,
            'skipped' => 0,
            'errors' => [],
        ];
        if (empty($diagnosis->repairablecompletionmissing)) {
            return $result;
        }

        $columns = $DB->get_columns('course_modules');
        $transaction = $DB->start_delegated_transaction();
        foreach ($diagnosis->repairablecompletionmissing as $activity) {
            if (empty($activity->cmid)) {
                $result->skipped++;
                continue;
            }

            $record = (object)[
                'id' => (int)$activity->cmid,
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
            ];
            if (array_key_exists('completionview', $columns)) {
                $record->completionview = 1;
            }
            if (array_key_exists('completiongradeitemnumber', $columns)) {
                $record->completiongradeitemnumber = null;
            }
            if (array_key_exists('completionpassgrade', $columns)) {
                $record->completionpassgrade = 0;
            }
            if (array_key_exists('completionexpected', $columns)) {
                $record->completionexpected = 0;
            }

            try {
                $DB->update_record('course_modules', $record);
                $result->fixed++;
            } catch (\Throwable $exception) {
                $result->skipped++;
                $result->errors[] = get_string('learningpathsyncrepaircompletionfailed', 'format_selfstudy',
                    $activity->name);
            }
        }
        $transaction->allow_commit();

        if ($result->fixed > 0) {
            rebuild_course_cache((int)$course->id, true);
        }

        return $result;
    }

    /**
     * Repairs broken availability structures in course modules.
     *
     * Invalid completion conditions are removed. Missing root show flags are
     * added because Moodle core requires them for valid availability trees.
     *
     * @param \stdClass $course
     * @param int $pathid
     * @return \stdClass
     */
    public function cleanup_invalid_availability(\stdClass $course, int $pathid): \stdClass {
        global $DB;

        $diagnosis = $this->diagnose($course, $pathid);
        $result = (object)[
            'fixed' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        $transaction = $DB->start_delegated_transaction();
        foreach ($diagnosis->activities as $activity) {
            if (empty($activity->availability) ||
                    (empty($activity->invalidcompletionavailability) && empty($activity->invalidstructureavailability))) {
                continue;
            }

            $availability = $this->repair_availability_json($activity->availability);
            if ($availability === false) {
                $result->skipped++;
                $result->errors[] = get_string('learningpathsynccleanupinvalidskipped', 'format_selfstudy',
                    $activity->name);
                continue;
            }

            $DB->set_field('course_modules', 'availability', $availability ?: null, ['id' => (int)$activity->cmid]);
            $result->fixed++;
        }
        $transaction->allow_commit();

        if ($result->fixed > 0) {
            rebuild_course_cache((int)$course->id, true);
        }

        return $result;
    }

    /**
     * Backwards-compatible wrapper for the previous cleanup method name.
     *
     * @param \stdClass $course
     * @param int $pathid
     * @return \stdClass
     */
    public function cleanup_invalid_completion_references(\stdClass $course, int $pathid): \stdClass {
        return $this->cleanup_invalid_availability($course, $pathid);
    }

    /**
     * Returns the availability JSON this service would assign to a course module.
     *
     * This method intentionally does not write to course_modules. The editor uses
     * it for preview; a later sync action can call it before replacing recognised
     * selfstudy rules.
     *
     * @param int[] $sourcecmids
     * @param bool $requireany
     * @return string
     */
    public function build_completion_availability_json(array $sourcecmids, bool $requireany = false): string {
        $conditions = [];
        foreach (array_values(array_unique(array_map('intval', $sourcecmids))) as $cmid) {
            if ($cmid <= 0) {
                continue;
            }
            $conditions[] = [
                'type' => 'completion',
                'cm' => $cmid,
                'e' => COMPLETION_COMPLETE,
            ];
        }

        if (!$conditions) {
            return '';
        }

        return json_encode([
            'op' => $requireany ? '|' : '&',
            'c' => $conditions,
            'showc' => array_fill(0, count($conditions), true),
            'show' => true,
        ]);
    }

    /**
     * Combines an existing Moodle availability tree with the planned path rule.
     *
     * @param string|null $existingjson
     * @param string $plannedjson
     * @return string|false
     */
    public function merge_with_existing_availability(?string $existingjson, string $plannedjson) {
        $planned = json_decode($plannedjson);
        if (!$planned) {
            return false;
        }

        if (empty($existingjson)) {
            return json_encode($planned);
        }

        $existing = json_decode($existingjson);
        if (!$existing) {
            return false;
        }

        $remaining = $this->remove_completion_conditions($existing);
        if ($remaining === null) {
            return json_encode($planned);
        }
        $remaining = $this->normalise_availability_structure($remaining);

        return json_encode((object)[
            'op' => '&',
            'c' => [$remaining, $planned],
            'showc' => [true, true],
            'show' => true,
        ]);
    }

    /**
     * Detects completion conditions already present in an availability tree.
     *
     * @param string|null $availability
     * @return int[]
     */
    public function get_existing_completion_condition_cmids(?string $availability): array {
        if (empty($availability)) {
            return [];
        }

        $tree = json_decode($availability);
        if (!$tree) {
            return [];
        }

        $cmids = [];
        $this->collect_completion_condition_cmids($tree, $cmids);
        return array_values(array_unique($cmids));
    }

    /**
     * Detects broken completion references in an availability tree.
     *
     * @param string|null $availability
     * @return bool
     */
    public function has_invalid_completion_condition_cmids(?string $availability): bool {
        if (empty($availability)) {
            return false;
        }

        $tree = json_decode($availability);
        if (!$tree) {
            return false;
        }

        return $this->has_invalid_completion_condition($tree);
    }

    /**
     * Detects availability trees that Moodle cannot read safely.
     *
     * @param string|null $availability
     * @return bool
     */
    public function has_invalid_availability_structure(?string $availability): bool {
        if (empty($availability)) {
            return false;
        }

        $tree = json_decode($availability);
        if (!$tree) {
            return true;
        }

        return $this->has_invalid_availability_node($tree);
    }

    /**
     * Collects activity level completion, grade and availability facts.
     *
     * @param \stdClass $course
     * @param \course_modinfo $modinfo
     * @param \completion_info $completion
     * @return \stdClass[]
     */
    private function get_course_module_diagnosis(\stdClass $course, \course_modinfo $modinfo,
            \completion_info $completion): array {
        global $DB;

        $activities = [];

        foreach ($modinfo->get_cms() as $cm) {
            if (activity_filter::is_excluded_station_module($cm)) {
                continue;
            }

            $gradeitem = \grade_item::fetch([
                'courseid' => $course->id,
                'itemtype' => 'mod',
                'itemmodule' => $cm->modname,
                'iteminstance' => $cm->instance,
            ]);
            $availability = (string)($cm->availability ?? '');
            $name = $DB->get_field($cm->modname, 'name', ['id' => (int)$cm->instance], IGNORE_MISSING);
            $name = $name !== false ? format_string((string)$name, true) :
                get_string('learningpathactivity', 'format_selfstudy') . ' ' . (int)$cm->id;

            $activities[(int)$cm->id] = (object)[
                'cmid' => (int)$cm->id,
                'name' => $name,
                'modname' => $cm->modname,
                'sectionnum' => (int)$cm->sectionnum,
                'completionenabled' => (bool)$completion->is_enabled($cm),
                'supportsgradepass' => !empty($gradeitem) && (float)$gradeitem->grademax > 0,
                'hasgradepass' => !empty($gradeitem) && (float)$gradeitem->gradepass > 0,
                'gradepass' => !empty($gradeitem) ? (float)$gradeitem->gradepass : 0.0,
                'availability' => $availability,
                'availabilitysummary' => $this->summarise_availability($availability),
                'invalidcompletionavailability' => $this->has_invalid_completion_condition_cmids($availability),
                'invalidstructureavailability' => $this->has_invalid_availability_structure($availability),
                'completionconditioncmids' => $this->get_existing_completion_condition_cmids($availability),
            ];
        }

        return $activities;
    }

    /**
     * Normalises stored path items and attaches course module facts.
     *
     * @param \stdClass $path
     * @param \course_modinfo $modinfo
     * @param \completion_info $completion
     * @param \stdClass[] $cminfo
     * @return \stdClass[]
     */
    private function prepare_items(\stdClass $path, \course_modinfo $modinfo, \completion_info $completion,
            array $cminfo): array {
        $children = [];
        foreach ($path->items as $item) {
            if (!empty($item->parentid)) {
                $children[(int)$item->parentid][] = $item;
            }
        }

        $items = [];
        foreach ($path->items as $item) {
            if (!empty($item->parentid)) {
                continue;
            }

            $prepared = $this->prepare_item($item, $modinfo, $completion, $cminfo);
            $prepared->children = [];
            foreach ($children[(int)$item->id] ?? [] as $child) {
                $prepared->children[] = $this->prepare_item($child, $modinfo, $completion, $cminfo);
            }
            $items[] = $prepared;
        }

        usort($items, static function(\stdClass $left, \stdClass $right): int {
            return ((int)$left->sortorder <=> (int)$right->sortorder) ?: ((int)$left->id <=> (int)$right->id);
        });

        return $items;
    }

    /**
     * Attaches module metadata to one path item.
     *
     * @param \stdClass $item
     * @param \course_modinfo $modinfo
     * @param \completion_info $completion
     * @param \stdClass[] $cminfo
     * @return \stdClass
     */
    private function prepare_item(\stdClass $item, \course_modinfo $modinfo, \completion_info $completion,
            array $cminfo): \stdClass {
        $prepared = clone($item);
        $prepared->cm = null;
        $prepared->activity = null;
        $prepared->label = $this->get_item_label($item);
        $prepared->completionenabled = false;

        if (!empty($item->cmid)) {
            try {
                $cm = $modinfo->get_cm((int)$item->cmid);
                $prepared->cm = $cm;
                $prepared->activity = $cminfo[(int)$cm->id] ?? null;
                if (!empty($prepared->activity)) {
                    $prepared->label = $prepared->activity->name;
                }
                $prepared->completionenabled = (bool)$completion->is_enabled($cm);
            } catch (\moodle_exception $exception) {
                $prepared->label = get_string('learningpathmissingactivity', 'format_selfstudy') . ' #' . (int)$item->cmid;
            }
        }

        return $prepared;
    }

    /**
     * Builds planned sync rules from milestone rows.
     *
     * @param \stdClass[] $items
     * @return \stdClass[]
     */
    private function preview_rules(array $items): array {
        $blocks = $this->build_milestone_blocks($items);
        if (!$blocks) {
            return $this->preview_legacy_rules($items);
        }

        $rules = [];
        foreach ($blocks as $block) {
            for ($index = 1; $index < count($block->rows); $index++) {
                $rules = array_merge($rules, $this->preview_row_rules(
                    $block->rows[$index - 1],
                    $block->rows[$index],
                    get_string('learningpathsyncrulewithinmilestone', 'format_selfstudy', (object)[
                        'milestone' => $block->milestone->label,
                        'row' => $index + 1,
                    ])
                ));
            }
        }

        $groups = $this->build_block_groups($blocks);
        for ($index = 1; $index < count($groups); $index++) {
            $source = $this->build_group_terminal_source($groups[$index - 1]);
            if (!$source) {
                continue;
            }
            foreach ($groups[$index] as $targetblock) {
                if (!empty($targetblock->rows[0])) {
                    $rules = array_merge($rules, $this->preview_row_rules(
                        $source,
                        $targetblock->rows[0],
                        get_string('learningpathsyncrulebetweenmilestones', 'format_selfstudy',
                            $targetblock->milestone->label)
                    ));
                }
            }
        }

        return $rules;
    }

    /**
     * Builds legacy adjacent rules for paths without milestone blocks.
     *
     * @param \stdClass[] $items
     * @return \stdClass[]
     */
    private function preview_legacy_rules(array $items): array {
        $rules = [];
        for ($index = 1; $index < count($items); $index++) {
            $rules[] = $this->preview_rule(
                $items[$index - 1],
                $items[$index],
                get_string('learningpathsyncrulelegacy', 'format_selfstudy')
            );
        }

        return $rules;
    }

    /**
     * Builds rules between two grid rows.
     *
     * @param \stdClass $source
     * @param \stdClass $target
     * @return \stdClass[]
     */
    private function preview_row_rules(\stdClass $source, \stdClass $target, string $context = ''): array {
        $rules = [];
        foreach ($this->get_target_items($target) as $targetitem) {
            $rules[] = $this->preview_rule($source, $targetitem, $context);
        }

        return $rules;
    }

    /**
     * Builds one planned dependency between adjacent path elements.
     *
     * @param \stdClass $source
     * @param \stdClass $target
     * @return \stdClass
     */
    private function preview_rule(\stdClass $source, \stdClass $target, string $context = ''): \stdClass {
        $rule = (object)[
            'source' => $source,
            'target' => $target,
            'context' => $context,
            'rulekind' => $source->itemtype === path_repository::ITEM_ALTERNATIVE ?
                get_string('learningpathsyncruleanyof', 'format_selfstudy') :
                get_string('learningpathsyncruleallof', 'format_selfstudy'),
            'sourcecmids' => [],
            'requireany' => false,
            'translatable' => true,
            'reason' => '',
            'availabilityjson' => '',
            'existingtargetavailability' => '',
            'existingcompletioncmids' => [],
        ];

        if ($target->itemtype !== path_repository::ITEM_STATION || empty($target->cmid)) {
            $rule->translatable = false;
            $rule->reason = get_string('learningpathsyncunsupportedtarget', 'format_selfstudy');
            return $rule;
        }

        if (!empty($target->activity)) {
            $rule->existingtargetavailability = $target->activity->availability;
            $rule->existingcompletioncmids = $target->activity->completionconditioncmids;
        }

        if ($source->itemtype === path_repository::ITEM_STATION && !empty($source->cmid)) {
            $rule->sourcecmids = [(int)$source->cmid];
            $rule->rulekind = get_string('learningpathsyncruleallof', 'format_selfstudy');
        } else if ($source->itemtype === path_repository::ITEM_ALTERNATIVE) {
            $rule->sourcecmids = $this->get_alternative_child_cmids($source);
            $rule->requireany = true;
            $rule->rulekind = get_string('learningpathsyncruleanyof', 'format_selfstudy');
        } else if ($source->itemtype === path_repository::ITEM_MILESTONE) {
            $rule->translatable = false;
            $rule->reason = get_string('learningpathsyncmilestoneinternal', 'format_selfstudy');
            return $rule;
        } else {
            $rule->translatable = false;
            $rule->reason = get_string('learningpathsyncunsupportedsource', 'format_selfstudy');
            return $rule;
        }

        if (!$rule->sourcecmids) {
            $rule->translatable = false;
            $rule->reason = get_string('learningpathsyncmissingsource', 'format_selfstudy');
            return $rule;
        }

        $missingcompletion = [];
        foreach ($this->get_source_items($source) as $sourceitem) {
            if (!empty($sourceitem->cmid) && empty($sourceitem->completionenabled)) {
                $missingcompletion[] = $sourceitem->label;
            }
        }
        if ($missingcompletion) {
            $rule->translatable = false;
            $rule->reason = get_string('learningpathsyncsourcecompletionmissing', 'format_selfstudy',
                implode(', ', $missingcompletion));
            return $rule;
        }

        $rule->availabilityjson = $this->build_completion_availability_json($rule->sourcecmids, $rule->requireany);
        return $rule;
    }

    /**
     * Returns source station items relevant for completion checks.
     *
     * @param \stdClass $source
     * @return \stdClass[]
     */
    private function get_source_items(\stdClass $source): array {
        if ($source->itemtype === path_repository::ITEM_ALTERNATIVE) {
            return $source->children ?? [];
        }

        return [$source];
    }

    /**
     * Returns source activities whose missing completion can be repaired safely.
     *
     * @param \stdClass[] $rules
     * @return \stdClass[]
     */
    private function collect_missing_completion_source_items(array $rules): array {
        $activities = [];
        foreach ($rules as $rule) {
            foreach ($this->get_source_items($rule->source) as $sourceitem) {
                if (empty($sourceitem->cmid) || !empty($sourceitem->completionenabled) || empty($sourceitem->activity)) {
                    continue;
                }
                $activities[(int)$sourceitem->cmid] = $sourceitem->activity;
            }
        }

        return array_values($activities);
    }

    /**
     * Returns target station items relevant for availability writes.
     *
     * @param \stdClass $target
     * @return \stdClass[]
     */
    private function get_target_items(\stdClass $target): array {
        if ($target->itemtype === path_repository::ITEM_ALTERNATIVE) {
            return array_values(array_filter($target->children ?? [], static function(\stdClass $child): bool {
                return $child->itemtype === path_repository::ITEM_STATION && !empty($child->cmid);
            }));
        }

        return [$target];
    }

    /**
     * Returns child course module ids of an alternative.
     *
     * @param \stdClass $alternative
     * @return int[]
     */
    private function get_alternative_child_cmids(\stdClass $alternative): array {
        $cmids = [];
        foreach ($alternative->children ?? [] as $child) {
            if ($child->itemtype === path_repository::ITEM_STATION && !empty($child->cmid)) {
                $cmids[] = (int)$child->cmid;
            }
        }
        return $cmids;
    }

    /**
     * Builds milestone blocks with their contained grid rows.
     *
     * @param \stdClass[] $items
     * @return \stdClass[]
     */
    private function build_milestone_blocks(array $items): array {
        $blocks = [];
        $current = null;
        foreach ($items as $item) {
            if ($item->itemtype === path_repository::ITEM_MILESTONE) {
                if ($current) {
                    $blocks[] = $current;
                }
                $current = (object)[
                    'milestone' => $item,
                    'key' => $this->get_milestone_key($item),
                    'alternativepeers' => $this->get_milestone_alternative_peers($item),
                    'rows' => [],
                ];
                continue;
            }

            if ($current && in_array($item->itemtype, [path_repository::ITEM_STATION, path_repository::ITEM_ALTERNATIVE], true)) {
                $current->rows[] = $item;
            }
        }
        if ($current) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * Groups milestone blocks so alternative milestones form one sequencing step.
     *
     * @param \stdClass[] $blocks
     * @return array
     */
    private function build_block_groups(array $blocks): array {
        $bykey = [];
        foreach ($blocks as $index => $block) {
            if ($block->key !== '') {
                $bykey[$block->key] = $index;
            }
        }

        $visited = [];
        $groups = [];
        foreach ($blocks as $index => $block) {
            if (!empty($visited[$index])) {
                continue;
            }
            $groupindexes = [];
            $this->collect_block_group_indexes($index, $blocks, $bykey, $visited, $groupindexes);
            sort($groupindexes);
            $groups[] = array_map(static function(int $groupindex) use ($blocks): \stdClass {
                return $blocks[$groupindex];
            }, $groupindexes);
        }

        return $groups;
    }

    /**
     * Collects connected alternative milestone block indexes.
     *
     * @param int $index
     * @param \stdClass[] $blocks
     * @param array $bykey
     * @param array $visited
     * @param int[] $groupindexes
     */
    private function collect_block_group_indexes(int $index, array $blocks, array $bykey, array &$visited,
            array &$groupindexes): void {
        if (!empty($visited[$index])) {
            return;
        }
        $visited[$index] = true;
        $groupindexes[] = $index;
        foreach ($blocks[$index]->alternativepeers as $peerkey) {
            if (isset($bykey[$peerkey])) {
                $this->collect_block_group_indexes($bykey[$peerkey], $blocks, $bykey, $visited, $groupindexes);
            }
        }
    }

    /**
     * Builds one source item from the terminal rows of a milestone group.
     *
     * @param \stdClass[] $group
     * @return \stdClass|null
     */
    private function build_group_terminal_source(array $group): ?\stdClass {
        $children = [];
        foreach ($group as $block) {
            $lastrow = $block->rows ? $block->rows[count($block->rows) - 1] : null;
            if (!$lastrow) {
                continue;
            }
            if ($lastrow->itemtype === path_repository::ITEM_STATION && !empty($lastrow->cmid)) {
                $children[] = $lastrow;
            } else if ($lastrow->itemtype === path_repository::ITEM_ALTERNATIVE) {
                $children = array_merge($children, $lastrow->children ?? []);
            }
        }
        $children = array_values(array_filter($children, static function(\stdClass $child): bool {
            return $child->itemtype === path_repository::ITEM_STATION && !empty($child->cmid);
        }));

        if (!$children) {
            return null;
        }
        if (count($children) === 1) {
            return $children[0];
        }

        return (object)[
            'itemtype' => path_repository::ITEM_ALTERNATIVE,
            'label' => get_string('learningpathsyncpreviousmilestonegroup', 'format_selfstudy'),
            'children' => $children,
            'completionenabled' => true,
        ];
    }

    /**
     * Returns a stable milestone key from configdata.
     *
     * @param \stdClass $milestone
     * @return string
     */
    private function get_milestone_key(\stdClass $milestone): string {
        $config = $this->decode_configdata((string)($milestone->configdata ?? ''));
        return clean_param((string)($config['milestonekey'] ?? ''), PARAM_ALPHANUMEXT);
    }

    /**
     * Returns milestone alternative peer keys from configdata.
     *
     * @param \stdClass $milestone
     * @return string[]
     */
    private function get_milestone_alternative_peers(\stdClass $milestone): array {
        $config = $this->decode_configdata((string)($milestone->configdata ?? ''));
        return array_values(array_filter(array_map(static function($value): string {
            return clean_param((string)$value, PARAM_ALPHANUMEXT);
        }, is_array($config['alternativepeers'] ?? null) ? $config['alternativepeers'] : [])));
    }

    /**
     * Decodes path item configdata.
     *
     * @param string $configdata
     * @return array
     */
    private function decode_configdata(string $configdata): array {
        if ($configdata === '') {
            return [];
        }
        $decoded = json_decode($configdata, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Returns a human-readable label for a path item.
     *
     * @param \stdClass $item
     * @return string
     */
    private function get_item_label(\stdClass $item): string {
        if (!empty($item->title)) {
            return format_string($item->title, true);
        }

        return get_string('learningpath' . $item->itemtype, 'format_selfstudy');
    }

    /**
     * Produces a compact availability summary.
     *
     * @param string|null $availability
     * @return string
     */
    private function summarise_availability(?string $availability): string {
        if (empty($availability)) {
            return '';
        }

        $tree = json_decode($availability);
        if (!$tree) {
            return get_string('learningpathsyncavailabilitycustom', 'format_selfstudy');
        }

        if ($this->has_invalid_availability_structure($availability)) {
            return get_string('learningpathsyncavailabilityinvalidstructure', 'format_selfstudy');
        }

        $cmids = $this->get_existing_completion_condition_cmids($availability);
        if ($cmids) {
            return get_string('learningpathsyncavailabilitycompletion', 'format_selfstudy', implode(', ', $cmids));
        }

        if ($this->has_invalid_completion_condition_cmids($availability)) {
            return get_string('learningpathsyncavailabilityinvalidcompletion', 'format_selfstudy');
        }

        return get_string('learningpathsyncavailabilityexists', 'format_selfstudy');
    }

    /**
     * Removes completion conditions from a Moodle availability tree.
     *
     * @param mixed $node
     * @return mixed|null
     */
    private function remove_completion_conditions($node) {
        if (is_object($node) && isset($node->type)) {
            return $node->type === 'completion' ? null : $node;
        }

        if (is_object($node) && isset($node->c) && is_array($node->c)) {
            $conditions = [];
            $showconditions = [];
            foreach ($node->c as $index => $condition) {
                $cleaned = $this->remove_completion_conditions($condition);
                if ($cleaned === null) {
                    continue;
                }
                $conditions[] = $cleaned;
                if (isset($node->showc) && is_array($node->showc) && array_key_exists($index, $node->showc)) {
                    $showconditions[] = (bool)$node->showc[$index];
                } else {
                    $showconditions[] = true;
                }
            }

            if (!$conditions) {
                return null;
            }

            $node->c = $conditions;
            $node->showc = $showconditions;
            return $node;
        }

        return $node;
    }

    /**
     * Removes invalid completion conditions from availability JSON.
     *
     * @param string|null $availability
     * @return string|false
     */
    private function remove_invalid_completion_references_from_json(?string $availability) {
        return $this->repair_availability_json($availability);
    }

    /**
     * Repairs availability JSON by removing invalid completion refs and adding missing show flags.
     *
     * @param string|null $availability
     * @return string|false
     */
    private function repair_availability_json(?string $availability) {
        if (empty($availability)) {
            return '';
        }

        $tree = json_decode($availability);
        if (!$tree) {
            return false;
        }

        $cleaned = $this->remove_invalid_completion_conditions($tree);
        if ($cleaned !== null) {
            $cleaned = $this->remove_invalid_availability_fragments($cleaned);
        }
        if ($cleaned !== null) {
            $cleaned = $this->normalise_availability_structure($cleaned);
        }
        return $cleaned === null ? '' : json_encode($cleaned);
    }

    /**
     * Adds required availability structure defaults.
     *
     * @param mixed $node
     * @return mixed
     */
    private function normalise_availability_structure($node) {
        if (is_object($node) && isset($node->c) && is_array($node->c)) {
            if (!property_exists($node, 'show')) {
                $node->show = true;
            }
            $showconditions = [];
            foreach ($node->c as $index => $condition) {
                $node->c[$index] = $this->normalise_availability_structure($condition);
                if (isset($node->showc) && is_array($node->showc) && array_key_exists($index, $node->showc)) {
                    $showconditions[] = (bool)$node->showc[$index];
                } else {
                    $showconditions[] = true;
                }
            }
            $node->showc = $showconditions;
            return $node;
        }

        if (is_array($node)) {
            foreach ($node as $index => $value) {
                $node[$index] = $this->normalise_availability_structure($value);
            }
        }

        return $node;
    }

    /**
     * Removes completion conditions with invalid course module ids.
     *
     * @param mixed $node
     * @return mixed|null
     */
    private function remove_invalid_completion_conditions($node) {
        if (is_object($node) && isset($node->type)) {
            if ($node->type === 'completion' && $this->is_invalid_completion_condition($node)) {
                return null;
            }
            return $node;
        }

        if (is_object($node) && isset($node->c) && is_array($node->c)) {
            $conditions = [];
            $showconditions = [];
            foreach ($node->c as $index => $condition) {
                $cleaned = $this->remove_invalid_completion_conditions($condition);
                if ($cleaned === null) {
                    continue;
                }
                $conditions[] = $cleaned;
                if (isset($node->showc) && is_array($node->showc) && array_key_exists($index, $node->showc)) {
                    $showconditions[] = (bool)$node->showc[$index];
                } else {
                    $showconditions[] = true;
                }
            }

            if (!$conditions) {
                return null;
            }

            $node->c = $conditions;
            $node->showc = $showconditions;
            return $node;
        }

        return $node;
    }

    /**
     * Removes stale empty or malformed availability fragments where possible.
     *
     * @param mixed $node
     * @return mixed|null
     */
    private function remove_invalid_availability_fragments($node) {
        if (is_object($node) && isset($node->c)) {
            if (!is_array($node->c)) {
                return null;
            }

            $conditions = [];
            $showconditions = [];
            foreach ($node->c as $index => $condition) {
                $cleaned = $this->remove_invalid_availability_fragments($condition);
                if ($cleaned === null) {
                    continue;
                }
                $conditions[] = $cleaned;
                if (isset($node->showc) && is_array($node->showc) && array_key_exists($index, $node->showc)) {
                    $showconditions[] = (bool)$node->showc[$index];
                } else {
                    $showconditions[] = true;
                }
            }

            if (!$conditions) {
                return null;
            }

            $node->c = $conditions;
            $node->showc = $showconditions;
            return $node;
        }

        if (is_object($node) && property_exists($node, 'type')) {
            return trim((string)$node->type) === '' ? null : $node;
        }

        return is_object($node) ? null : $node;
    }

    /**
     * Checks whether one completion availability condition points to no usable module.
     *
     * @param \stdClass $node
     * @return bool
     */
    private function is_invalid_completion_condition(\stdClass $node): bool {
        global $DB;

        if (!isset($node->cm) || (int)$node->cm <= 0) {
            return true;
        }

        return !$DB->record_exists('course_modules', ['id' => (int)$node->cm]);
    }

    /**
     * Detects malformed or stale availability fragments.
     *
     * @param mixed $node
     * @return bool
     */
    private function has_invalid_availability_node($node): bool {
        if (is_object($node) && isset($node->c)) {
            if (!is_array($node->c) || !property_exists($node, 'show')) {
                return true;
            }
            if (isset($node->showc) && (!is_array($node->showc) || count($node->showc) !== count($node->c))) {
                return true;
            }
            foreach ($node->c as $condition) {
                if ($this->has_invalid_availability_node($condition)) {
                    return true;
                }
            }
            return false;
        }

        if (is_object($node) && property_exists($node, 'type')) {
            if ((string)$node->type === '') {
                return true;
            }
            if ($node->type === 'completion' && $this->is_invalid_completion_condition($node)) {
                return true;
            }
            return false;
        }

        if (is_object($node) && !property_exists($node, 'type')) {
            return true;
        }

        if (is_array($node)) {
            foreach ($node as $value) {
                if ($this->has_invalid_availability_node($value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Recursively collects course module ids from completion availability conditions.
     *
     * @param mixed $node
     * @param int[] $cmids
     */
    private function collect_completion_condition_cmids($node, array &$cmids): void {
        if (is_object($node)) {
            if (($node->type ?? '') === 'completion' && !empty($node->cm)) {
                $cmid = (int)$node->cm;
                if ($cmid > 0) {
                    $cmids[] = $cmid;
                }
            }
            foreach (get_object_vars($node) as $value) {
                $this->collect_completion_condition_cmids($value, $cmids);
            }
            return;
        }

        if (is_array($node)) {
            foreach ($node as $value) {
                $this->collect_completion_condition_cmids($value, $cmids);
            }
        }
    }

    /**
     * Recursively checks for invalid completion activity references.
     *
     * @param mixed $node
     * @return bool
     */
    private function has_invalid_completion_condition($node): bool {
        if (is_object($node)) {
            if (($node->type ?? '') === 'completion' && $this->is_invalid_completion_condition($node)) {
                return true;
            }
            foreach (get_object_vars($node) as $value) {
                if ($this->has_invalid_completion_condition($value)) {
                    return true;
                }
            }
            return false;
        }

        if (is_array($node)) {
            foreach ($node as $value) {
                if ($this->has_invalid_completion_condition($value)) {
                    return true;
                }
            }
        }

        return false;
    }
}
