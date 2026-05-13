<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Calculates learner progress for selfstudy learning paths.
 */
class path_progress {

    /**
     * Calculates progress for a learner in a path.
     *
     * @param \stdClass $course
     * @param int $pathid
     * @param int $userid
     * @return \stdClass
     */
    public static function calculate(\stdClass $course, int $pathid, int $userid): \stdClass {
        $repository = new path_repository();
        $tree = self::get_runtime_tree($course, $pathid, $repository);
        $completedmilestones = $repository->get_completed_milestone_itemids($pathid, $userid);

        $result = (object)[
            'total' => 0,
            'complete' => 0,
            'percentage' => 0,
            'nextcmid' => 0,
            'nexturl' => null,
        ];

        try {
            $modinfo = get_fast_modinfo($course, $userid);
            $completion = new \completion_info($course);
        } catch (\Throwable $exception) {
            return $result;
        }

        if (self::has_top_level_path_groups($tree)) {
            foreach (self::build_milestone_block_groups($tree) as $group) {
                $groupresult = self::evaluate_milestone_block_group($group, $modinfo, $completion, $userid,
                    $completedmilestones);
                $result->total += $groupresult->total;
                $result->complete += $groupresult->complete;
                if (!$result->nextcmid && $groupresult->nextcmid) {
                    $result->nextcmid = $groupresult->nextcmid;
                    $result->nexturl = $groupresult->nexturl;
                }
            }
        } else {
            foreach ($tree as $item) {
                $itemresult = self::evaluate_item($item, $modinfo, $completion, $userid, $completedmilestones);
                $result->total += $itemresult->total;
                $result->complete += $itemresult->complete;
                if (!$result->nextcmid && $itemresult->nextcmid) {
                    $result->nextcmid = $itemresult->nextcmid;
                    $result->nexturl = $itemresult->nexturl;
                }
            }
        }

        if ($result->total > 0) {
            $result->percentage = (int)floor(($result->complete / $result->total) * 100);
        }

        return $result;
    }

    /**
     * Returns a flattened outline with status information for rendering.
     *
     * @param \stdClass $course
     * @param int $pathid
     * @param int $userid
     * @return \stdClass[]
     */
    public static function outline(\stdClass $course, int $pathid, int $userid): array {
        $repository = new path_repository();
        $tree = self::get_runtime_tree($course, $pathid, $repository);
        $completedmilestones = $repository->get_completed_milestone_itemids($pathid, $userid);

        try {
            $modinfo = get_fast_modinfo($course, $userid);
            $completion = new \completion_info($course);
        } catch (\Throwable $exception) {
            return [];
        }

        $outline = [];
        if (self::has_top_level_path_groups($tree)) {
            self::append_milestone_grouped_outline($outline, $tree, $modinfo, $completion, (int)$course->id, $userid,
                $completedmilestones);
        } else {
            foreach ($tree as $item) {
                self::append_outline_item($outline, $item, $modinfo, $completion, (int)$course->id, $userid,
                    $completedmilestones, 0);
            }
        }

        $hascurrent = false;
        foreach ($outline as $entry) {
            if ($entry->status === 'current') {
                $hascurrent = true;
                break;
            }
        }
        if (!$hascurrent) {
            foreach ($outline as $entry) {
                if (in_array($entry->status, ['open', 'started', 'review'], true) && !empty($entry->url)) {
                    $entry->status = 'recommended';
                    break;
                }
            }
        }

        return $outline;
    }

    /**
     * Returns path items from the runtime snapshot read layer.
     *
     * @param \stdClass $course
     * @param int $pathid
     * @param path_repository $repository
     * @return \stdClass[]
     */
    private static function get_runtime_tree(\stdClass $course, int $pathid, path_repository $repository): array {
        $runtime = new path_runtime($repository);
        $snapshot = $runtime->get_runtime_snapshot($course, $pathid);
        if (!$snapshot || empty($snapshot->decoded) || !is_array($snapshot->decoded)) {
            return $repository->get_path_tree($pathid);
        }

        return self::snapshot_to_tree($snapshot->decoded);
    }

    /**
     * Converts decoded runtime snapshot data into the legacy item tree shape.
     *
     * @param array $snapshot
     * @return \stdClass[]
     */
    private static function snapshot_to_tree(array $snapshot): array {
        $nodes = [];
        foreach (($snapshot['nodes'] ?? []) as $node) {
            if (!is_array($node) || empty($node['key'])) {
                continue;
            }
            $nodes[(string)$node['key']] = $node;
        }

        $tree = [];
        foreach (($snapshot['root'] ?? []) as $rootkey) {
            $rootkey = (string)$rootkey;
            if (isset($nodes[$rootkey])) {
                $tree[] = self::snapshot_node_to_item($nodes[$rootkey], $nodes, 0);
            }
        }

        return $tree;
    }

    /**
     * Converts one runtime node into a path item compatible object.
     *
     * @param array $node
     * @param array $nodes
     * @param int $parentid
     * @return \stdClass
     */
    private static function snapshot_node_to_item(array $node, array $nodes, int $parentid): \stdClass {
        $type = (string)($node['type'] ?? '');
        $key = (string)($node['key'] ?? '');
        $config = [];
        if ($type === path_repository::ITEM_MILESTONE) {
            $config['milestonekey'] = self::milestone_key_from_snapshot_key($key);
            $config['alternativepeers'] = array_values(array_filter(array_map('strval',
                is_array($node['alternativepeers'] ?? null) ? $node['alternativepeers'] : [])));
            if (!empty($node['sectionnum'])) {
                $config['sectionnum'] = (int)$node['sectionnum'];
            }
        }

        $item = (object)[
            'id' => 0,
            'parentid' => $parentid,
            'itemtype' => $type,
            'cmid' => (int)($node['cmid'] ?? 0),
            'sectionid' => (int)($node['sectionid'] ?? 0),
            'title' => (string)($node['title'] ?? ''),
            'description' => '',
            'availabilitymode' => path_repository::AVAILABILITY_SHOW,
            'configdata' => $config ? json_encode($config) : '',
            'sortorder' => (int)($node['sortorder'] ?? 0),
            'children' => [],
        ];

        foreach (($node['children'] ?? []) as $childkey) {
            $childkey = (string)$childkey;
            if (isset($nodes[$childkey])) {
                $item->children[] = self::snapshot_node_to_item($nodes[$childkey], $nodes, 0);
            }
        }

        return $item;
    }

    /**
     * Returns a milestone key from a runtime node key.
     *
     * @param string $key
     * @return string
     */
    private static function milestone_key_from_snapshot_key(string $key): string {
        if (strpos($key, 'milestone-') === 0) {
            return substr($key, strlen('milestone-'));
        }

        return $key;
    }

    /**
     * Returns whether the top-level path contains display groups.
     *
     * @param \stdClass[] $tree
     * @return bool
     */
    private static function has_top_level_path_groups(array $tree): bool {
        foreach ($tree as $item) {
            if (in_array($item->itemtype, [path_repository::ITEM_MILESTONE, path_repository::ITEM_SEQUENCE], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluates one item recursively.
     *
     * @param \stdClass $item
     * @param \course_modinfo $modinfo
     * @param \completion_info $completion
     * @param int $courseid
     * @param int $userid
     * @param int[] $completedmilestones
     * @return \stdClass
     */
    private static function evaluate_item(\stdClass $item, \course_modinfo $modinfo, \completion_info $completion,
            int $userid, array $completedmilestones): \stdClass {
        if ($item->itemtype === path_repository::ITEM_STATION) {
            return self::evaluate_station($item, $modinfo, $completion, $userid);
        }

        if ($item->itemtype === path_repository::ITEM_MILESTONE) {
            return (object)[
                'total' => 0,
                'complete' => 0,
                'nextcmid' => 0,
                'nexturl' => null,
            ];
        }

        $children = $item->children ?? [];
        if ($item->itemtype === path_repository::ITEM_ALTERNATIVE) {
            return self::evaluate_alternative($children, $modinfo, $completion, $userid, $completedmilestones);
        }

        return self::evaluate_sequence($children, $modinfo, $completion, $userid, $completedmilestones);
    }

    /**
     * Evaluates a station item.
     *
     * @param \stdClass $item
     * @param \course_modinfo $modinfo
     * @param \completion_info $completion
     * @param int $userid
     * @return \stdClass
     */
    private static function evaluate_station(\stdClass $item, \course_modinfo $modinfo, \completion_info $completion,
            int $userid): \stdClass {
        $result = (object)[
            'total' => 1,
            'complete' => 0,
            'nextcmid' => 0,
            'nexturl' => null,
        ];

        try {
            $cm = $modinfo->get_cm((int)$item->cmid);
        } catch (\Throwable $exception) {
            return $result;
        }

        if ($completion->is_enabled($cm)) {
            $data = $completion->get_data($cm, false, $userid);
            if (self::is_completion_done((int)$data->completionstate)) {
                $result->complete = 1;
                return $result;
            }
        }

        if (!empty($cm->url) && $cm->uservisible) {
            $result->nextcmid = (int)$cm->id;
            $result->nexturl = $cm->url->out(false);
        }

        return $result;
    }

    /**
     * Evaluates an alternative. One completed child completes the alternative.
     *
     * @param \stdClass[] $children
     * @param \course_modinfo $modinfo
     * @param \completion_info $completion
     * @param int $userid
     * @param int[] $completedmilestones
     * @return \stdClass
     */
    private static function evaluate_alternative(array $children, \course_modinfo $modinfo, \completion_info $completion,
            int $userid, array $completedmilestones): \stdClass {
        $result = (object)[
            'total' => $children ? 1 : 0,
            'complete' => 0,
            'nextcmid' => 0,
            'nexturl' => null,
        ];

        foreach ($children as $child) {
            $childresult = self::evaluate_item($child, $modinfo, $completion, $userid, $completedmilestones);
            if ($childresult->complete > 0) {
                $result->complete = 1;
            }
            if (!$result->nextcmid && $childresult->nextcmid) {
                $result->nextcmid = $childresult->nextcmid;
                $result->nexturl = $childresult->nexturl;
            }
        }

        if ($result->complete) {
            $result->nextcmid = 0;
            $result->nexturl = null;
        }

        return $result;
    }

    /**
     * Evaluates a sequence by summing all children.
     *
     * @param \stdClass[] $children
     * @param \course_modinfo $modinfo
     * @param \completion_info $completion
     * @param int $userid
     * @param int[] $completedmilestones
     * @return \stdClass
     */
    private static function evaluate_sequence(array $children, \course_modinfo $modinfo, \completion_info $completion,
            int $userid, array $completedmilestones): \stdClass {
        $result = (object)[
            'total' => 0,
            'complete' => 0,
            'nextcmid' => 0,
            'nexturl' => null,
        ];

        foreach ($children as $child) {
            $childresult = self::evaluate_item($child, $modinfo, $completion, $userid, $completedmilestones);
            $result->total += $childresult->total;
            $result->complete += $childresult->complete;
            if (!$result->nextcmid && $childresult->nextcmid) {
                $result->nextcmid = $childresult->nextcmid;
                $result->nexturl = $childresult->nexturl;
            }
        }

        return $result;
    }

    /**
     * Evaluates a milestone block or an alternative group of milestone blocks.
     *
     * @param \stdClass[] $group
     * @param \course_modinfo $modinfo
     * @param \completion_info $completion
     * @param int $userid
     * @param int[] $completedmilestones
     * @return \stdClass
     */
    private static function evaluate_milestone_block_group(array $group, \course_modinfo $modinfo,
            \completion_info $completion, int $userid, array $completedmilestones): \stdClass {
        if (count($group) <= 1) {
            return self::evaluate_milestone_block($group[0], $modinfo, $completion, $userid, $completedmilestones);
        }

        $result = (object)[
            'total' => $group ? 1 : 0,
            'complete' => 0,
            'nextcmid' => 0,
            'nexturl' => null,
        ];
        foreach ($group as $block) {
            $blockresult = self::evaluate_milestone_block($block, $modinfo, $completion, $userid, $completedmilestones);
            if ($blockresult->total > 0 && $blockresult->complete >= $blockresult->total) {
                $result->complete = 1;
            }
            if (!$result->nextcmid && $blockresult->nextcmid) {
                $result->nextcmid = $blockresult->nextcmid;
                $result->nexturl = $blockresult->nexturl;
            }
        }
        if ($result->complete) {
            $result->nextcmid = 0;
            $result->nexturl = null;
        }

        return $result;
    }

    /**
     * Evaluates one milestone and the activities attached to it.
     *
     * @param \stdClass $block
     * @param \course_modinfo $modinfo
     * @param \completion_info $completion
     * @param int $userid
     * @param int[] $completedmilestones
     * @return \stdClass
     */
    private static function evaluate_milestone_block(\stdClass $block, \course_modinfo $modinfo,
            \completion_info $completion, int $userid, array $completedmilestones): \stdClass {
        $result = self::evaluate_sequence($block->items ?? [], $modinfo, $completion, $userid, $completedmilestones);
        if ($result->total > 0) {
            return $result;
        }

        return (object)[
            'total' => 0,
            'complete' => 0,
            'nextcmid' => 0,
            'nexturl' => null,
        ];
    }

    /**
     * Appends one outline item and its children.
     *
     * @param array $outline
     * @param \stdClass $item
     * @param \course_modinfo $modinfo
     * @param \completion_info $completion
     * @param int $userid
     * @param int[] $completedmilestones
     * @param int $level
     */
    private static function append_outline_item(array &$outline, \stdClass $item, \course_modinfo $modinfo,
            \completion_info $completion, int $courseid, int $userid, array $completedmilestones, int $level): void {
        $entry = (object)[
            'id' => (int)$item->id,
            'type' => $item->itemtype,
            'level' => $level,
            'title' => trim((string)$item->title),
            'status' => 'open',
            'url' => null,
            'actionurl' => null,
            'actionlabel' => null,
            'cmid' => 0,
            'iconurl' => '',
            'iconalt' => '',
            'timemodified' => 0,
            'availableinfo' => '',
            'competencies' => [],
        ];

        if ($item->itemtype === path_repository::ITEM_STATION) {
            try {
                $cm = $modinfo->get_cm((int)$item->cmid);
                $entry->cmid = (int)$cm->id;
                $entry->iconurl = $cm->get_icon_url()->out(false);
                $entry->iconalt = get_string('pluginname', 'mod_' . $cm->modname);
                $entry->title = $entry->title !== '' ? $entry->title : format_string($cm->name, true);
                $entry->url = !empty($cm->url) && $cm->uservisible ? $cm->url->out(false) : null;
                if (function_exists('format_selfstudy_get_cm_core_competency_labels')) {
                    $entry->competencies = format_selfstudy_get_cm_core_competency_labels($courseid, (int)$cm->id);
                }
                $entry->availableinfo = self::format_available_info((string)($cm->availableinfo ?? ''),
                    $courseid);
                if (!$cm->uservisible || (property_exists($cm, 'available') && empty($cm->available))) {
                    $entry->status = 'locked';
                }
                if ($completion->is_enabled($cm)) {
                    $data = $completion->get_data($cm, false, $userid);
                    $entry->timemodified = (int)($data->timemodified ?? 0);
                    if ((int)$data->completionstate === COMPLETION_COMPLETE_FAIL) {
                        $entry->status = 'review';
                    } else if (self::is_completion_done((int)$data->completionstate)) {
                        $entry->status = 'complete';
                    } else if ($entry->status !== 'locked' && $entry->timemodified > 0) {
                        $entry->status = 'started';
                    } else if ($entry->status !== 'locked') {
                        $entry->status = 'open';
                    }
                }
                if ($entry->status !== 'locked') {
                    $entry->availableinfo = '';
                }
            } catch (\Throwable $exception) {
                $entry->status = 'missing';
                $entry->title = $entry->title !== '' ? $entry->title : get_string('learningpathmissingactivity', 'format_selfstudy');
            }
            $outline[] = $entry;
            return;
        }

        if ($item->itemtype === path_repository::ITEM_MILESTONE) {
            $entry->title = $entry->title !== '' ? $entry->title : get_string('learningpathmilestone', 'format_selfstudy');
            $entry->status = 'open';
            $entry->actionurl = null;
            $entry->actionlabel = null;
            $outline[] = $entry;
            return;
        }

        $children = $item->children ?? [];
        if ($item->itemtype === path_repository::ITEM_ALTERNATIVE) {
            self::append_alternative_outline_items($outline, $children, $modinfo, $completion, $courseid, $userid,
                $completedmilestones, $level);
            return;
        }

        $itemresult = self::evaluate_sequence($children, $modinfo, $completion, $userid, $completedmilestones);
        $entry->title = $entry->title !== '' ? $entry->title : get_string('learningpath', 'format_selfstudy');
        $entry->status = ($itemresult->total > 0 && $itemresult->complete >= $itemresult->total) ? 'complete' : 'open';
        $outline[] = $entry;

        foreach ($children as $child) {
            self::append_outline_item($outline, $child, $modinfo, $completion, $courseid, $userid,
                $completedmilestones, $level + 1);
        }
    }

    /**
     * Appends the outline grouped by milestones.
     *
     * @param array $outline
     * @param \stdClass[] $tree
     * @param \course_modinfo $modinfo
     * @param \completion_info $completion
     * @param int $courseid
     * @param int $userid
     * @param int[] $completedmilestones
     */
    private static function append_milestone_grouped_outline(array &$outline, array $tree, \course_modinfo $modinfo,
            \completion_info $completion, int $courseid, int $userid, array $completedmilestones): void {
        $groups = self::build_milestone_block_groups($tree);
        if ($groups) {
            foreach ($groups as $groupindex => $group) {
                $alternativegroup = count($group) > 1 ? 'milestone-alternative-' . $groupindex : '';
                foreach ($group as $blockindex => $block) {
                    self::append_milestone_group($outline, $block->milestone, $block->items, $modinfo, $completion,
                        $courseid, $userid, $completedmilestones, $alternativegroup, $blockindex);
                }
            }
            return;
        }

        $currentmilestone = null;
        $currentitems = [];
        $orphanitems = [];

        $flush = static function(?\stdClass $milestone, array $items) use (&$outline, $modinfo, $completion, $courseid,
                $userid, $completedmilestones): void {
            if (!$milestone) {
                return;
            }
            self::append_milestone_group($outline, $milestone, $items, $modinfo, $completion, $courseid, $userid,
                $completedmilestones);
        };

        foreach ($tree as $item) {
            if (in_array($item->itemtype, [path_repository::ITEM_MILESTONE, path_repository::ITEM_SEQUENCE], true)) {
                $flush($currentmilestone, $currentitems);
                $currentmilestone = $item;
                $currentitems = $orphanitems;
                foreach (($item->children ?? []) as $child) {
                    $currentitems[] = $child;
                }
                $orphanitems = [];
                continue;
            }

            if ($currentmilestone) {
                $currentitems[] = $item;
            } else {
                $orphanitems[] = $item;
            }
        }

        $flush($currentmilestone, $currentitems);
    }

    /**
     * Appends one milestone header and the visible activities within it.
     *
     * @param array $outline
     * @param \stdClass $milestone
     * @param \stdClass[] $items
     * @param \course_modinfo $modinfo
     * @param \completion_info $completion
     * @param int $courseid
     * @param int $userid
     * @param int[] $completedmilestones
     */
    private static function append_milestone_group(array &$outline, \stdClass $milestone, array $items,
            \course_modinfo $modinfo, \completion_info $completion, int $courseid, int $userid,
            array $completedmilestones, string $alternativegroup = '', int $alternativeindex = 0): void {
        $children = [];
        $groupresult = (object)['total' => 0, 'complete' => 0];

        foreach ($items as $item) {
            $itemoutline = [];
            self::append_outline_item($itemoutline, $item, $modinfo, $completion, $courseid, $userid,
                $completedmilestones, 0);
            foreach ($itemoutline as $entry) {
                if ($entry->type === path_repository::ITEM_MILESTONE) {
                    continue;
                }
                $children[] = $entry;
            }

            $itemresult = self::evaluate_item($item, $modinfo, $completion, $userid, $completedmilestones);
            $groupresult->total += $itemresult->total;
            $groupresult->complete += $itemresult->complete;
        }

        $iscomplete = $groupresult->total > 0 && $groupresult->complete >= $groupresult->total;
        $header = (object)[
            'id' => (int)$milestone->id,
            'type' => path_repository::ITEM_MILESTONE,
            'level' => 0,
            'title' => self::get_group_title($milestone, $modinfo, $items),
            'status' => $iscomplete ? 'complete' : 'open',
            'url' => null,
            'actionurl' => null,
            'actionlabel' => null,
            'cmid' => 0,
            'iconurl' => '',
            'iconalt' => '',
            'timemodified' => 0,
            'availableinfo' => '',
            'milestonecomplete' => $iscomplete,
        ];
        if ($alternativegroup !== '') {
            $header->alternativegroup = $alternativegroup;
            $header->alternativechoice = true;
            $header->alternativechoiceindex = $alternativeindex;
        }
        $outline[] = $header;

        $lastindex = count($children) - 1;
        foreach ($children as $index => $entry) {
            $entry->milestonechild = true;
            $entry->milestonegroupstart = $index === 0;
            $entry->milestonegroupend = $index === $lastindex;
            if ($alternativegroup !== '') {
                $entry->alternativegroup = $alternativegroup;
                $entry->alternativechoice = true;
                $entry->alternativechoiceindex = $alternativeindex;
            }
            $outline[] = $entry;
        }
    }

    /**
     * Returns milestone blocks grouped by alternative milestone relationships.
     *
     * @param \stdClass[] $tree
     * @return array
     */
    private static function build_milestone_block_groups(array $tree): array {
        $blocks = self::build_milestone_blocks($tree);
        if (!$blocks) {
            return [];
        }

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
            self::collect_milestone_block_group($index, $blocks, $bykey, $visited, $indexes);
            sort($indexes);
            $groups[] = array_map(static function(int $groupindex) use ($blocks): \stdClass {
                return $blocks[$groupindex];
            }, $indexes);
        }

        return $groups;
    }

    /**
     * Builds milestone blocks from the flat top-level tree.
     *
     * @param \stdClass[] $tree
     * @return \stdClass[]
     */
    private static function build_milestone_blocks(array $tree): array {
        $blocks = [];
        $current = null;
        $orphanitems = [];

        foreach ($tree as $item) {
            if (in_array($item->itemtype, [path_repository::ITEM_MILESTONE, path_repository::ITEM_SEQUENCE], true)) {
                if ($current) {
                    $blocks[] = $current;
                }
                $current = (object)[
                    'milestone' => $item,
                    'key' => self::get_milestone_key($item),
                    'alternativepeers' => self::get_milestone_alternative_peers($item),
                    'items' => $orphanitems,
                ];
                foreach (($item->children ?? []) as $child) {
                    $current->items[] = $child;
                }
                $orphanitems = [];
                continue;
            }

            if ($current) {
                $current->items[] = $item;
            } else {
                $orphanitems[] = $item;
            }
        }

        if ($current) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * Collects one connected milestone alternative group.
     *
     * @param int $index
     * @param \stdClass[] $blocks
     * @param array $bykey
     * @param array $visited
     * @param int[] $indexes
     */
    private static function collect_milestone_block_group(int $index, array $blocks, array $bykey, array &$visited,
            array &$indexes): void {
        if (!empty($visited[$index])) {
            return;
        }
        $visited[$index] = true;
        $indexes[] = $index;

        foreach ($blocks[$index]->alternativepeers as $peerkey) {
            if (isset($bykey[$peerkey])) {
                self::collect_milestone_block_group($bykey[$peerkey], $blocks, $bykey, $visited, $indexes);
            }
        }
    }

    /**
     * Returns a stable milestone key from item config.
     *
     * @param \stdClass $item
     * @return string
     */
    private static function get_milestone_key(\stdClass $item): string {
        $config = self::decode_configdata((string)($item->configdata ?? ''));
        return clean_param((string)($config['milestonekey'] ?? ''), PARAM_ALPHANUMEXT);
    }

    /**
     * Returns alternative milestone peer keys from item config.
     *
     * @param \stdClass $item
     * @return string[]
     */
    private static function get_milestone_alternative_peers(\stdClass $item): array {
        $config = self::decode_configdata((string)($item->configdata ?? ''));
        return array_values(array_unique(array_filter(array_map(static function($value): string {
            return clean_param((string)$value, PARAM_ALPHANUMEXT);
        }, is_array($config['alternativepeers'] ?? null) ? $config['alternativepeers'] : []))));
    }

    /**
     * Decodes item configdata.
     *
     * @param string $configdata
     * @return array
     */
    private static function decode_configdata(string $configdata): array {
        if ($configdata === '') {
            return [];
        }
        $decoded = json_decode($configdata, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Returns a display title for a milestone or sequence group.
     *
     * @param \stdClass $item
     * @param \course_modinfo $modinfo
     * @param \stdClass[] $items
     * @return string
     */
    private static function get_group_title(\stdClass $item, \course_modinfo $modinfo, array $items = []): string {
        $title = trim((string)($item->title ?? ''));
        if ($title !== '') {
            return $title;
        }

        if ($item->itemtype === path_repository::ITEM_SEQUENCE && !empty($item->sectionid)) {
            try {
                $section = $modinfo->get_section_info_by_id((int)$item->sectionid);
                if ($section) {
                    $sectionname = self::get_section_display_name($section, $modinfo);
                    if ($sectionname !== '') {
                        return $sectionname;
                    }
                    if (!empty($section->section)) {
                        return get_string('learningpathmilestone', 'format_selfstudy') . ' ' . (int)$section->section;
                    }
                }
            } catch (\Throwable $exception) {
                // Fall through to the generic label.
            }
        }

        foreach ($items as $child) {
            $sectionname = self::get_first_child_section_name($child, $modinfo);
            if ($sectionname !== '') {
                return $sectionname;
            }
        }

        return get_string('learningpathmilestone', 'format_selfstudy');
    }

    /**
     * Returns the section name for the first station inside an item branch.
     *
     * @param \stdClass $item
     * @param \course_modinfo $modinfo
     * @return string
     */
    private static function get_first_child_section_name(\stdClass $item, \course_modinfo $modinfo): string {
        if ($item->itemtype === path_repository::ITEM_STATION && !empty($item->cmid)) {
            try {
                $cm = $modinfo->get_cm((int)$item->cmid);
                $section = $modinfo->get_section_info_by_id((int)$cm->section);
                if ($section) {
                    $sectionname = self::get_section_display_name($section, $modinfo);
                    if ($sectionname !== '') {
                        return $sectionname;
                    }
                    if (!empty($section->section)) {
                        return get_string('learningpathmilestone', 'format_selfstudy') . ' ' . (int)$section->section;
                    }
                }
            } catch (\Throwable $exception) {
                return '';
            }
        }

        foreach (($item->children ?? []) as $child) {
            $sectionname = self::get_first_child_section_name($child, $modinfo);
            if ($sectionname !== '') {
                return $sectionname;
            }
        }

        return '';
    }

    /**
     * Returns a robust display name for a course section.
     *
     * @param \section_info $section
     * @param \course_modinfo $modinfo
     * @return string
     */
    private static function get_section_display_name(\section_info $section, \course_modinfo $modinfo): string {
        $sectionname = trim((string)($section->name ?? ''));
        if ($sectionname !== '') {
            return format_string($sectionname, true);
        }

        $sectionname = trim((string)get_section_name($modinfo->get_course(), $section));
        return $sectionname !== '' ? $sectionname : '';
    }

    /**
     * Appends alternative children as inline path entries.
     *
     * @param array $outline
     * @param \stdClass[] $children
     * @param \course_modinfo $modinfo
     * @param \completion_info $completion
     * @param int $courseid
     * @param int $userid
     * @param int[] $completedmilestones
     * @param int $level
     */
    private static function append_alternative_outline_items(array &$outline, array $children, \course_modinfo $modinfo,
            \completion_info $completion, int $courseid, int $userid, array $completedmilestones, int $level): void {
        $alternatives = [];
        $completedalternatives = [];

        foreach ($children as $child) {
            $candidate = [];
            self::append_outline_item($candidate, $child, $modinfo, $completion, $courseid, $userid,
                $completedmilestones, $level);
            if (!$candidate) {
                continue;
            }

            $alternatives[] = $candidate;
            foreach ($candidate as $entry) {
                if (($entry->status ?? '') === 'complete') {
                    $completedalternatives[] = $candidate;
                    break;
                }
            }
        }

        $visiblealternatives = $completedalternatives ?: $alternatives;
        $groupkey = count($visiblealternatives) > 1 ? 'alternative-' . count($outline) . '-' . count($visiblealternatives) : '';
        foreach ($visiblealternatives as $candidateindex => $candidate) {
            foreach ($candidate as $entry) {
                if ($groupkey !== '') {
                    $entry->alternativegroup = $groupkey;
                    $entry->alternativechoice = true;
                    $entry->alternativechoiceindex = $candidateindex;
                }
                $outline[] = $entry;
            }
        }
    }

    /**
     * Formats availability text and removes Moodle placeholder markup.
     *
     * @param string $availableinfo
     * @param int $courseid
     * @return string
     */
    private static function format_available_info(string $availableinfo, int $courseid): string {
        $availableinfo = trim($availableinfo);
        if ($availableinfo === '') {
            return '';
        }

        try {
            $availableinfo = \core_availability\info::format_info($availableinfo, $courseid);
        } catch (\Throwable $exception) {
            if (strpos($availableinfo, '<AVAILABILITY_') !== false) {
                return '';
            }
        }

        $availableinfo = preg_replace('/<[^>]+>/', ' ', $availableinfo);
        $availableinfo = html_entity_decode($availableinfo, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $availableinfo = trim(preg_replace('/\s+/', ' ', $availableinfo));
        if (strpos($availableinfo, 'AVAILABILITY_') !== false) {
            return '';
        }

        return $availableinfo;
    }

    /**
     * Checks whether a completion state is done.
     *
     * @param int $completionstate
     * @return bool
     */
    private static function is_completion_done(int $completionstate): bool {
        return $completionstate === COMPLETION_COMPLETE || $completionstate === COMPLETION_COMPLETE_PASS;
    }
}
