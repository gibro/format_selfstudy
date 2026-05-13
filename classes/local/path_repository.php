<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Persistence API for selfstudy learning paths.
 */
class path_repository {

    /** @var string Learning path table. */
    private const TABLE_PATHS = 'format_selfstudy_paths';

    /** @var string Learning path item table. */
    private const TABLE_ITEMS = 'format_selfstudy_path_items';

    /** @var string Learner path choices table. */
    private const TABLE_CHOICES = 'format_selfstudy_choices';

    /** @var string Milestone completion table. */
    private const TABLE_MILESTONES = 'format_selfstudy_milestones';

    /** @var string Runtime snapshot table. */
    private const TABLE_SNAPSHOTS = 'format_selfstudy_snapshots';

    /** @var string Station item type. */
    public const ITEM_STATION = 'station';

    /** @var string Alternative item type. */
    public const ITEM_ALTERNATIVE = 'alternative';

    /** @var string Milestone item type. */
    public const ITEM_MILESTONE = 'milestone';

    /** @var string Section/sequence item type. */
    public const ITEM_SEQUENCE = 'sequence';

    /** @var string Hidden until available. */
    public const AVAILABILITY_HIDDEN = 'hidden';

    /** @var string Visible but greyed out until available. */
    public const AVAILABILITY_DISABLED = 'disabled';

    /** @var string Visible until available, but not enterable. */
    public const AVAILABILITY_SHOW = 'show';

    /**
     * Returns all paths for a course.
     *
     * @param int $courseid
     * @param bool $onlyenabled
     * @return \stdClass[]
     */
    public function get_paths(int $courseid, bool $onlyenabled = false, int $userid = 0): array {
        global $DB;

        $conditions = ['courseid' => $courseid, 'userid' => $userid];
        if ($onlyenabled) {
            $conditions['enabled'] = 1;
        }

        return array_values($DB->get_records(self::TABLE_PATHS, $conditions, 'sortorder ASC, id ASC'));
    }

    /**
     * Returns one path record.
     *
     * @param int $pathid
     * @return \stdClass|null
     */
    public function get_path(int $pathid): ?\stdClass {
        global $DB;

        return $DB->get_record(self::TABLE_PATHS, ['id' => $pathid], '*', IGNORE_MISSING) ?: null;
    }

    /**
     * Returns a path and its items as a flat ordered list.
     *
     * @param int $pathid
     * @return \stdClass|null
     */
    public function get_path_with_items(int $pathid): ?\stdClass {
        $path = $this->get_path($pathid);
        if (!$path) {
            return null;
        }

        $path->items = $this->get_path_items($pathid);
        return $path;
    }

    /**
     * Returns all items in a path.
     *
     * @param int $pathid
     * @return \stdClass[]
     */
    public function get_path_items(int $pathid): array {
        global $DB;

        return array_values($DB->get_records(self::TABLE_ITEMS, ['pathid' => $pathid],
            'parentid ASC, sortorder ASC, id ASC'));
    }

    /**
     * Returns one path item.
     *
     * @param int $itemid
     * @return \stdClass|null
     */
    public function get_item(int $itemid): ?\stdClass {
        global $DB;

        return $DB->get_record(self::TABLE_ITEMS, ['id' => $itemid], '*', IGNORE_MISSING) ?: null;
    }

    /**
     * Returns path items as a nested tree.
     *
     * @param int $pathid
     * @return \stdClass[]
     */
    public function get_path_tree(int $pathid): array {
        $items = $this->get_path_items($pathid);
        $byparent = [];

        foreach ($items as $item) {
            $item->children = [];
            $byparent[(int)$item->parentid][] = $item;
        }

        return $this->build_item_tree($byparent, 0);
    }

    /**
     * Creates a new learning path.
     *
     * @param int $courseid
     * @param array $data
     * @return int
     */
    public function create_path(int $courseid, array $data): int {
        global $DB;

        $now = time();
        $record = $this->normalise_path_record($data);
        $record->courseid = $courseid;
        $record->userid = clean_param($data['userid'] ?? ($record->userid ?? 0), PARAM_INT);
        $record->sortorder = $record->sortorder ?? $this->get_next_path_sortorder($courseid);
        $record->timecreated = $now;
        $record->timemodified = $now;

        return (int)$DB->insert_record(self::TABLE_PATHS, $record);
    }

    /**
     * Updates an existing learning path.
     *
     * @param int $pathid
     * @param array $data
     */
    public function update_path(int $pathid, array $data): void {
        global $DB;

        $existing = $DB->get_record(self::TABLE_PATHS, ['id' => $pathid], '*', MUST_EXIST);
        $record = $this->normalise_path_record($data, $existing);
        $record->id = $pathid;
        $record->courseid = (int)$existing->courseid;
        $record->userid = (int)($existing->userid ?? 0);
        $record->timecreated = (int)$existing->timecreated;
        $record->timemodified = time();

        $DB->update_record(self::TABLE_PATHS, $record);
    }

    /**
     * Publishes one course-level path and unpublishes other course-level paths.
     *
     * @param int $courseid
     * @param int $pathid
     */
    public function publish_course_path(int $courseid, int $pathid): void {
        global $DB;

        $path = $DB->get_record(self::TABLE_PATHS, [
            'id' => $pathid,
            'courseid' => $courseid,
            'userid' => 0,
        ], '*', MUST_EXIST);

        $transaction = $DB->start_delegated_transaction();
        $DB->set_field(self::TABLE_PATHS, 'enabled', 0, [
            'courseid' => $courseid,
            'userid' => 0,
        ]);
        $DB->set_field(self::TABLE_PATHS, 'enabled', 1, ['id' => (int)$path->id]);
        $DB->set_field(self::TABLE_PATHS, 'timemodified', time(), ['id' => (int)$path->id]);
        $transaction->allow_commit();
    }

    /**
     * Deletes a path and dependent path data.
     *
     * @param int $pathid
     */
    public function delete_path(int $pathid): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        $itemids = $DB->get_fieldset_select(self::TABLE_ITEMS, 'id', 'pathid = :pathid', ['pathid' => $pathid]);

        if ($itemids) {
            [$insql, $params] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'itemid');
            $DB->delete_records_select(self::TABLE_MILESTONES, "itemid $insql", $params);
        }

        $DB->delete_records(self::TABLE_CHOICES, ['pathid' => $pathid]);
        $DB->delete_records(self::TABLE_SNAPSHOTS, ['pathid' => $pathid]);
        $DB->delete_records(self::TABLE_ITEMS, ['pathid' => $pathid]);
        $DB->delete_records(self::TABLE_PATHS, ['id' => $pathid]);

        $transaction->allow_commit();
    }

    /**
     * Replaces all items in a path.
     *
     * Items may contain nested children. If an item contains tempid, the returned
     * array maps that tempid to the inserted database id.
     *
     * @param int $pathid
     * @param array $items
     * @return array
     */
    public function replace_path_items(int $pathid, array $items): array {
        global $DB;

        $DB->get_record(self::TABLE_PATHS, ['id' => $pathid], 'id', MUST_EXIST);
        $transaction = $DB->start_delegated_transaction();
        $itemids = $DB->get_fieldset_select(self::TABLE_ITEMS, 'id', 'pathid = :pathid', ['pathid' => $pathid]);

        if ($itemids) {
            [$insql, $params] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'itemid');
            $DB->delete_records_select(self::TABLE_MILESTONES, "itemid $insql", $params);
        }
        $DB->delete_records(self::TABLE_ITEMS, ['pathid' => $pathid]);

        $idmap = [];
        $this->insert_item_branch($pathid, 0, $items, $idmap);
        $transaction->allow_commit();

        return $idmap;
    }

    /**
     * Stores a learner's active path choice.
     *
     * @param int $courseid
     * @param int $userid
     * @param int $pathid
     */
    public function set_active_path(int $courseid, int $userid, int $pathid): void {
        global $DB;

        $path = $DB->get_record(self::TABLE_PATHS, ['id' => $pathid, 'courseid' => $courseid], '*', MUST_EXIST);
        $pathowner = (int)($path->userid ?? 0);
        if ($pathowner !== 0 && $pathowner !== $userid) {
            throw new \coding_exception('Cannot select another learner personal path.');
        }
        $existing = $DB->get_record(self::TABLE_CHOICES, ['courseid' => $courseid, 'userid' => $userid], '*', IGNORE_MISSING);
        $now = time();

        if ($existing) {
            $existing->pathid = (int)$path->id;
            $existing->timemodified = $now;
            $DB->update_record(self::TABLE_CHOICES, $existing);
            return;
        }

        $DB->insert_record(self::TABLE_CHOICES, (object)[
            'courseid' => $courseid,
            'userid' => $userid,
            'pathid' => (int)$path->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Returns the active path selected by a learner.
     *
     * @param int $courseid
     * @param int $userid
     * @return \stdClass|null
     */
    public function get_active_path(int $courseid, int $userid): ?\stdClass {
        global $DB;

        $sql = "SELECT p.*
                  FROM {" . self::TABLE_PATHS . "} p
                  JOIN {" . self::TABLE_CHOICES . "} c ON c.pathid = p.id
                 WHERE c.courseid = :courseid
                   AND c.userid = :userid";

        return $DB->get_record_sql($sql, ['courseid' => $courseid, 'userid' => $userid], IGNORE_MISSING) ?: null;
    }

    /**
     * Creates or replaces one learner's personal path from selected sections.
     *
     * Only the path structure is replaced. Moodle core activity completion is
     * stored per course module and learner, so previous activity completions
     * remain available when the learner changes paths later.
     *
     * @param \stdClass $course
     * @param int $userid
     * @param int[] $sectionids
     * @return int
     */
    public function create_personal_path_from_sections(\stdClass $course, int $userid, array $sectionids): int {
        $modinfo = get_fast_modinfo($course);
        $sectionids = array_values(array_unique(array_filter(array_map('intval', $sectionids))));
        $items = [];

        foreach ($modinfo->get_section_info_all() as $section) {
            if (!$section->uservisible || !in_array((int)$section->id, $sectionids, true)) {
                continue;
            }

            $children = [];
            foreach ($modinfo->sections[$section->section] ?? [] as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if (!activity_filter::is_learning_station($cm, true)) {
                    continue;
                }
                $children[] = [
                    'itemtype' => self::ITEM_STATION,
                    'cmid' => (int)$cm->id,
                    'sortorder' => count($children),
                ];
            }

            if (!$children) {
                continue;
            }

            $items[] = [
                'itemtype' => self::ITEM_SEQUENCE,
                'sectionid' => (int)$section->id,
                'title' => get_section_name($course, $section),
                'sortorder' => count($items),
                'children' => $children,
            ];
        }

        if (!$items) {
            throw new \coding_exception('Personal paths require at least one usable section.');
        }

        $existing = $this->get_personal_path((int)$course->id, $userid);
        $pathdata = [
            'name' => get_string('learningpathpersonalname', 'format_selfstudy'),
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
            'enabled' => 1,
            'userid' => $userid,
        ];

        if ($existing) {
            $this->update_path((int)$existing->id, $pathdata);
            $pathid = (int)$existing->id;
        } else {
            $pathid = $this->create_path((int)$course->id, $pathdata);
        }

        $this->replace_path_items($pathid, $items);
        $this->set_active_path((int)$course->id, $userid, $pathid);

        return $pathid;
    }

    /**
     * Creates or replaces one learner's personal path from a published template path.
     *
     * Only the path structure is replaced. Moodle core activity completion is
     * stored per course module and learner, so previous activity completions
     * remain available when the learner changes paths later.
     *
     * @param \stdClass $course
     * @param int $userid
     * @param int $templatepathid
     * @param string[] $selectedmilestonekeys
     * @return int
     */
    public function create_personal_path_from_template(\stdClass $course, int $userid, int $templatepathid,
            array $selectedmilestonekeys): int {
        $template = $this->get_path_with_items($templatepathid);
        if (!$template || (int)$template->courseid !== (int)$course->id || empty($template->enabled) ||
                (int)($template->userid ?? 0) !== 0) {
            throw new \coding_exception('Personal paths require a published course path template.');
        }

        $selected = array_flip(array_values(array_unique(array_filter(array_map(static function($key): string {
            return clean_param((string)$key, PARAM_ALPHANUMEXT);
        }, $selectedmilestonekeys)))));
        $blocks = $this->get_milestone_blocks_from_items($template->items ?? []);
        $groups = $this->group_milestone_blocks($blocks);
        $items = [];

        foreach ($groups as $group) {
            $hasalternatives = count($group) > 1;
            $chosen = [];
            foreach ($group as $block) {
                if (!$hasalternatives || isset($selected[$block->key])) {
                    $chosen[] = $block;
                }
            }
            if ($hasalternatives && !$chosen) {
                throw new \coding_exception('Each alternative milestone group needs at least one selection.');
            }
            foreach ($chosen as $block) {
                foreach ($block->items as $item) {
                    $items[] = $this->export_item_for_copy($item);
                }
            }
        }

        if (!$items) {
            throw new \coding_exception('Personal paths require at least one usable milestone.');
        }

        $existing = $this->get_personal_path((int)$course->id, $userid);
        $pathdata = [
            'name' => get_string('learningpathpersonalname', 'format_selfstudy'),
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
            'enabled' => 1,
            'userid' => $userid,
        ];

        if ($existing) {
            $this->update_path((int)$existing->id, $pathdata);
            $pathid = (int)$existing->id;
        } else {
            $pathid = $this->create_path((int)$course->id, $pathdata);
        }

        $this->replace_path_items($pathid, $items);
        $this->set_active_path((int)$course->id, $userid, $pathid);

        return $pathid;
    }

    /**
     * Returns a learner's personal path for a course.
     *
     * @param int $courseid
     * @param int $userid
     * @return \stdClass|null
     */
    public function get_personal_path(int $courseid, int $userid): ?\stdClass {
        global $DB;

        return $DB->get_record(self::TABLE_PATHS, [
            'courseid' => $courseid,
            'userid' => $userid,
        ], '*', IGNORE_MISSING) ?: null;
    }

    /**
     * Sets completion state for a path milestone item.
     *
     * @param int $itemid
     * @param int $userid
     * @param bool $completed
     */
    public function set_milestone_completion(int $itemid, int $userid, bool $completed): void {
        global $DB;

        $item = $DB->get_record(self::TABLE_ITEMS, ['id' => $itemid, 'itemtype' => self::ITEM_MILESTONE], 'id', MUST_EXIST);
        $existing = $DB->get_record(self::TABLE_MILESTONES, ['itemid' => $item->id, 'userid' => $userid], '*', IGNORE_MISSING);
        $now = time();

        if ($existing) {
            $existing->completed = $completed ? 1 : 0;
            $existing->timecompleted = $completed ? ($existing->timecompleted ?: $now) : 0;
            $existing->timemodified = $now;
            $DB->update_record(self::TABLE_MILESTONES, $existing);
            return;
        }

        $DB->insert_record(self::TABLE_MILESTONES, (object)[
            'itemid' => (int)$item->id,
            'userid' => $userid,
            'completed' => $completed ? 1 : 0,
            'timecompleted' => $completed ? $now : 0,
            'timemodified' => $now,
        ]);
    }

    /**
     * Returns completed milestone ids for a learner within a path.
     *
     * @param int $pathid
     * @param int $userid
     * @return int[]
     */
    public function get_completed_milestone_itemids(int $pathid, int $userid): array {
        global $DB;

        $sql = "SELECT i.id
                  FROM {" . self::TABLE_ITEMS . "} i
                  JOIN {" . self::TABLE_MILESTONES . "} m ON m.itemid = i.id
                 WHERE i.pathid = :pathid
                   AND i.itemtype = :itemtype
                   AND m.userid = :userid
                   AND m.completed = 1";

        return array_map('intval', $DB->get_fieldset_sql($sql, [
            'pathid' => $pathid,
            'itemtype' => self::ITEM_MILESTONE,
            'userid' => $userid,
        ]));
    }

    /**
     * Builds a nested tree from records grouped by parent id.
     *
     * @param array $byparent
     * @param int $parentid
     * @return \stdClass[]
     */
    private function build_item_tree(array $byparent, int $parentid): array {
        $branch = $byparent[$parentid] ?? [];
        foreach ($branch as $item) {
            $item->children = $this->build_item_tree($byparent, (int)$item->id);
        }
        return $branch;
    }

    /**
     * Returns milestone blocks from a flat item list.
     *
     * @param \stdClass[] $items
     * @return \stdClass[]
     */
    private function get_milestone_blocks_from_items(array $items): array {
        $children = [];
        foreach ($items as $item) {
            if (!empty($item->parentid)) {
                $children[(int)$item->parentid][] = $item;
            }
        }

        $blocks = [];
        $current = null;
        foreach ($items as $item) {
            if (!empty($item->parentid)) {
                continue;
            }
            $item->children = $children[(int)$item->id] ?? [];
            if ($item->itemtype === self::ITEM_MILESTONE) {
                if ($current) {
                    $blocks[] = $current;
                }
                $current = (object)[
                    'key' => $this->get_item_config_value($item, 'milestonekey'),
                    'alternativepeers' => $this->get_item_config_array($item, 'alternativepeers'),
                    'items' => [$item],
                ];
                continue;
            }
            if ($current) {
                $current->items[] = $item;
            }
        }
        if ($current) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * Groups milestone blocks by alternative peer relationships.
     *
     * @param \stdClass[] $blocks
     * @return array
     */
    private function group_milestone_blocks(array $blocks): array {
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
            $indexes = [];
            $this->collect_milestone_block_group($index, $blocks, $bykey, $visited, $indexes);
            sort($indexes);
            $groups[] = array_map(static function(int $groupindex) use ($blocks): \stdClass {
                return $blocks[$groupindex];
            }, $indexes);
        }

        return $groups;
    }

    /**
     * Collects one connected milestone block group.
     *
     * @param int $index
     * @param \stdClass[] $blocks
     * @param array $bykey
     * @param array $visited
     * @param int[] $indexes
     */
    private function collect_milestone_block_group(int $index, array $blocks, array $bykey, array &$visited,
            array &$indexes): void {
        if (!empty($visited[$index])) {
            return;
        }
        $visited[$index] = true;
        $indexes[] = $index;
        foreach ($blocks[$index]->alternativepeers as $peerkey) {
            if (isset($bykey[$peerkey])) {
                $this->collect_milestone_block_group($bykey[$peerkey], $blocks, $bykey, $visited, $indexes);
            }
        }
    }

    /**
     * Converts an item record into insertable data without ids.
     *
     * @param \stdClass $item
     * @return array
     */
    private function export_item_for_copy(\stdClass $item): array {
        $copy = [
            'itemtype' => $item->itemtype,
            'cmid' => (int)$item->cmid,
            'sectionid' => (int)$item->sectionid,
            'title' => (string)$item->title,
            'description' => (string)$item->description,
            'descriptionformat' => (int)$item->descriptionformat,
            'availabilitymode' => (string)$item->availabilitymode,
            'configdata' => (string)$item->configdata,
            'sortorder' => (int)$item->sortorder,
        ];
        $children = [];
        foreach ($item->children ?? [] as $child) {
            $children[] = $this->export_item_for_copy($child);
        }
        if ($children) {
            $copy['children'] = $children;
        }

        return $copy;
    }

    /**
     * Returns one item config value.
     *
     * @param \stdClass $item
     * @param string $name
     * @return string
     */
    private function get_item_config_value(\stdClass $item, string $name): string {
        $config = json_decode((string)($item->configdata ?? ''), true);
        return is_array($config) ? clean_param((string)($config[$name] ?? ''), PARAM_ALPHANUMEXT) : '';
    }

    /**
     * Returns one item config array.
     *
     * @param \stdClass $item
     * @param string $name
     * @return string[]
     */
    private function get_item_config_array(\stdClass $item, string $name): array {
        $config = json_decode((string)($item->configdata ?? ''), true);
        $values = is_array($config) && is_array($config[$name] ?? null) ? $config[$name] : [];
        return array_values(array_filter(array_map(static function($value): string {
            return clean_param((string)$value, PARAM_ALPHANUMEXT);
        }, $values)));
    }

    /**
     * Inserts a branch of nested path items.
     *
     * @param int $pathid
     * @param int $parentid
     * @param array $items
     * @param array $idmap
     */
    private function insert_item_branch(int $pathid, int $parentid, array $items, array &$idmap): void {
        global $DB;

        $sortorder = 0;
        foreach ($items as $item) {
            $children = $item['children'] ?? [];
            $record = $this->normalise_item_record($item);
            $record->pathid = $pathid;
            $record->parentid = $parentid;
            $record->sortorder = $record->sortorder ?? $sortorder;
            $record->timecreated = time();
            $record->timemodified = $record->timecreated;

            $itemid = (int)$DB->insert_record(self::TABLE_ITEMS, $record);
            if (!empty($item['tempid'])) {
                $idmap[(string)$item['tempid']] = $itemid;
            }

            if (is_array($children) && $children) {
                $this->insert_item_branch($pathid, $itemid, $children, $idmap);
            }

            $sortorder++;
        }
    }

    /**
     * Normalises path input.
     *
     * @param array $data
     * @param \stdClass|null $defaults
     * @return \stdClass
     */
    private function normalise_path_record(array $data, ?\stdClass $defaults = null): \stdClass {
        $record = $defaults ? clone($defaults) : new \stdClass();

        $record->name = clean_param($data['name'] ?? ($record->name ?? ''), PARAM_TEXT);
        $record->description = clean_param($data['description'] ?? ($record->description ?? ''), PARAM_RAW);
        $record->descriptionformat = clean_param($data['descriptionformat'] ?? ($record->descriptionformat ?? FORMAT_HTML), PARAM_INT);
        $record->imageurl = clean_param($data['imageurl'] ?? ($record->imageurl ?? ''), PARAM_URL);
        $record->icon = clean_param($data['icon'] ?? ($record->icon ?? ''), PARAM_ALPHANUMEXT);
        if (array_key_exists('enabled', $data)) {
            $record->enabled = empty($data['enabled']) ? 0 : 1;
        } else {
            $record->enabled = $defaults ? (int)$defaults->enabled : 1;
        }

        if (array_key_exists('sortorder', $data)) {
            $record->sortorder = clean_param($data['sortorder'], PARAM_INT);
        }
        if (array_key_exists('userid', $data)) {
            $record->userid = clean_param($data['userid'], PARAM_INT);
        } else if (!$defaults && !isset($record->userid)) {
            $record->userid = 0;
        }

        if ($record->name === '') {
            throw new \coding_exception('Learning path name must not be empty.');
        }

        return $record;
    }

    /**
     * Normalises item input.
     *
     * @param array $data
     * @return \stdClass
     */
    private function normalise_item_record(array $data): \stdClass {
        $record = new \stdClass();
        $record->itemtype = clean_param($data['itemtype'] ?? self::ITEM_STATION, PARAM_ALPHANUMEXT);
        $this->validate_itemtype($record->itemtype);

        $record->cmid = clean_param($data['cmid'] ?? 0, PARAM_INT);
        $record->sectionid = clean_param($data['sectionid'] ?? 0, PARAM_INT);
        $record->title = clean_param($data['title'] ?? '', PARAM_TEXT);
        $record->description = clean_param($data['description'] ?? '', PARAM_RAW);
        $record->descriptionformat = clean_param($data['descriptionformat'] ?? FORMAT_HTML, PARAM_INT);
        $record->availabilitymode = clean_param($data['availabilitymode'] ?? self::AVAILABILITY_SHOW, PARAM_ALPHANUMEXT);
        $record->configdata = $this->normalise_configdata($data['configdata'] ?? '');

        if (array_key_exists('sortorder', $data)) {
            $record->sortorder = clean_param($data['sortorder'], PARAM_INT);
        }

        if ($record->itemtype === self::ITEM_STATION && !$record->cmid) {
            throw new \coding_exception('Station items require a course module id.');
        }

        return $record;
    }

    /**
     * Validates a path item type.
     *
     * @param string $itemtype
     */
    private function validate_itemtype(string $itemtype): void {
        $validtypes = [
            self::ITEM_STATION,
            self::ITEM_ALTERNATIVE,
            self::ITEM_MILESTONE,
            self::ITEM_SEQUENCE,
        ];

        if (!in_array($itemtype, $validtypes, true)) {
            throw new \coding_exception('Invalid learning path item type: ' . $itemtype);
        }
    }

    /**
     * Normalises config data for storage.
     *
     * @param mixed $configdata
     * @return string
     */
    private function normalise_configdata($configdata): string {
        if (is_array($configdata) || is_object($configdata)) {
            return json_encode($configdata);
        }

        return clean_param((string)$configdata, PARAM_RAW);
    }

    /**
     * Returns the next sort order value for a new course path.
     *
     * @param int $courseid
     * @return int
     */
    private function get_next_path_sortorder(int $courseid): int {
        global $DB;

        $maxsort = $DB->get_field_sql(
            "SELECT MAX(sortorder) FROM {" . self::TABLE_PATHS . "} WHERE courseid = :courseid",
            ['courseid' => $courseid]
        );
        return $maxsort === false ? 0 : ((int)$maxsort + 1);
    }
}
