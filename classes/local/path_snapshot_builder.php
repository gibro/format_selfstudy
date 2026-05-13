<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds stable runtime snapshots from authored learning path items.
 */
class path_snapshot_builder {

    /** @var int Current runtime snapshot schema version. */
    public const SCHEMA_VERSION = 1;

    /**
     * Builds a normalised runtime snapshot for a path.
     *
     * @param \stdClass $course
     * @param \stdClass $path Path record with an items property.
     * @return \stdClass
     */
    public function build_snapshot(\stdClass $course, \stdClass $path): \stdClass {
        $items = $this->prepare_items($path->items ?? []);
        $modinfo = get_fast_modinfo($course);

        $nodes = [];
        $root = [];
        $currentcontainerkey = null;
        $usedkeys = [];

        foreach ($items as $item) {
            if (!empty($item->parentid)) {
                continue;
            }

            if ($this->is_container_item($item)) {
                $node = $this->build_container_node($item, $modinfo, $usedkeys);
                $nodes[$node['key']] = $node;
                $root[] = $node['key'];
                $currentcontainerkey = $node['key'];
                continue;
            }

            $node = $this->build_runtime_node($item, $modinfo, $usedkeys);
            if (!$node) {
                continue;
            }

            $this->append_node($nodes, $node);
            if ($currentcontainerkey !== null) {
                $nodes[$currentcontainerkey]['children'][] = $node['key'];
            } else {
                $root[] = $node['key'];
            }
        }

        $snapshot = [
            'schema' => self::SCHEMA_VERSION,
            'path' => [
                'id' => (int)$path->id,
                'courseid' => (int)$course->id,
                'userid' => (int)($path->userid ?? 0),
                'name' => (string)($path->name ?? ''),
                'description' => (string)($path->description ?? ''),
                'timemodified' => (int)($path->timemodified ?? 0),
            ],
            'nodes' => array_values($nodes),
            'root' => $root,
            'rules' => [],
        ];

        $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return (object)[
            'schema' => self::SCHEMA_VERSION,
            'json' => $json,
            'sourcehash' => $this->source_hash($course, $path, $items),
        ];
    }

    /**
     * Builds one non-container runtime node.
     *
     * @param \stdClass $item
     * @param \course_modinfo $modinfo
     * @param array $usedkeys
     * @return array|null
     */
    private function build_runtime_node(\stdClass $item, \course_modinfo $modinfo, array &$usedkeys): ?array {
        if ($item->itemtype === path_repository::ITEM_STATION) {
            if (empty($item->cmid)) {
                return null;
            }

            return [
                'key' => $this->unique_key('station-' . (int)$item->cmid, $usedkeys),
                'type' => path_repository::ITEM_STATION,
                'cmid' => (int)$item->cmid,
                'title' => $this->get_station_title($item, $modinfo),
                'level' => 1,
                'sortorder' => (int)($item->sortorder ?? 0),
            ];
        }

        if ($item->itemtype === path_repository::ITEM_ALTERNATIVE) {
            $children = [];
            foreach (($item->children ?? []) as $child) {
                $childnode = $this->build_runtime_node($child, $modinfo, $usedkeys);
                if ($childnode) {
                    $children[] = $childnode;
                }
            }

            if (!$children) {
                return null;
            }

            $key = $this->unique_key('alternative-' . (int)($item->id ?? 0), $usedkeys);
            return [
                'key' => $key,
                'type' => path_repository::ITEM_ALTERNATIVE,
                'title' => $this->clean_title((string)($item->title ?? '')),
                'level' => 1,
                'sortorder' => (int)($item->sortorder ?? 0),
                'children' => array_column($children, 'key'),
                '_inlinechildren' => $children,
            ];
        }

        return null;
    }

    /**
     * Appends a node and any inline child nodes to the snapshot node map.
     *
     * @param array $nodes
     * @param array $node
     */
    private function append_node(array &$nodes, array $node): void {
        $inlinechildren = $node['_inlinechildren'] ?? [];
        unset($node['_inlinechildren']);

        foreach ($inlinechildren as $child) {
            $this->append_node($nodes, $child);
        }
        $nodes[$node['key']] = $node;
    }

    /**
     * Builds one milestone or sequence container node.
     *
     * @param \stdClass $item
     * @param \course_modinfo $modinfo
     * @param array $usedkeys
     * @return array
     */
    private function build_container_node(\stdClass $item, \course_modinfo $modinfo, array &$usedkeys): array {
        $config = $this->decode_configdata((string)($item->configdata ?? ''));
        $sectionid = (int)($item->sectionid ?? 0);
        $sectionnum = (int)($config['sectionnum'] ?? 0);

        if ($sectionid && $sectionnum === 0) {
            try {
                $section = $modinfo->get_section_info_by_id($sectionid);
                $sectionnum = $section ? (int)$section->section : 0;
            } catch (\Throwable $exception) {
                $sectionnum = 0;
            }
        }

        $basekey = $this->container_base_key($item, $config, $sectionid, $sectionnum);
        $node = [
            'key' => $this->unique_key($basekey, $usedkeys),
            'type' => (string)$item->itemtype,
            'title' => $this->get_container_title($item, $modinfo),
            'level' => 0,
            'sortorder' => (int)($item->sortorder ?? 0),
            'alternativepeers' => $this->clean_string_array($config['alternativepeers'] ?? []),
            'children' => [],
        ];

        if ($sectionid > 0) {
            $node['sectionid'] = $sectionid;
        }
        if ($sectionnum > 0) {
            $node['sectionnum'] = $sectionnum;
        }

        return $node;
    }

    /**
     * Prepares flat items with nested alternative children.
     *
     * @param mixed $items
     * @return \stdClass[]
     */
    private function prepare_items($items): array {
        if (!is_array($items)) {
            return [];
        }

        $byid = [];
        foreach ($items as $item) {
            if (!is_object($item)) {
                continue;
            }
            $item->children = [];
            $byid[(int)($item->id ?? 0)] = $item;
        }

        $roots = [];
        foreach ($byid as $item) {
            $parentid = (int)($item->parentid ?? 0);
            if ($parentid > 0 && isset($byid[$parentid])) {
                $byid[$parentid]->children[] = $item;
            } else {
                $roots[] = $item;
            }
        }

        $sort = static function(\stdClass $left, \stdClass $right): int {
            $order = ((int)($left->sortorder ?? 0)) <=> ((int)($right->sortorder ?? 0));
            return $order !== 0 ? $order : ((int)($left->id ?? 0) <=> (int)($right->id ?? 0));
        };
        usort($roots, $sort);
        foreach ($byid as $item) {
            usort($item->children, $sort);
        }

        return $roots;
    }

    /**
     * Returns whether an item opens a root runtime group.
     *
     * @param \stdClass $item
     * @return bool
     */
    private function is_container_item(\stdClass $item): bool {
        return in_array($item->itemtype, [path_repository::ITEM_MILESTONE, path_repository::ITEM_SEQUENCE], true);
    }

    /**
     * Returns a stable base key for a container item.
     *
     * @param \stdClass $item
     * @param array $config
     * @param int $sectionid
     * @param int $sectionnum
     * @return string
     */
    private function container_base_key(\stdClass $item, array $config, int $sectionid, int $sectionnum): string {
        $milestonekey = clean_param((string)($config['milestonekey'] ?? ''), PARAM_ALPHANUMEXT);
        if ($milestonekey !== '') {
            return 'milestone-' . $milestonekey;
        }
        if ($sectionid > 0) {
            return 'section-' . $sectionid;
        }
        if ($sectionnum > 0) {
            return 'sectionnum-' . $sectionnum;
        }

        return (string)$item->itemtype . '-' . (int)($item->id ?? 0);
    }

    /**
     * Returns a unique key within the snapshot.
     *
     * @param string $base
     * @param array $usedkeys
     * @return string
     */
    private function unique_key(string $base, array &$usedkeys): string {
        $base = clean_param($base, PARAM_ALPHANUMEXT);
        if ($base === '') {
            $base = 'node';
        }

        $key = $base;
        $suffix = 2;
        while (isset($usedkeys[$key])) {
            $key = $base . '-' . $suffix;
            $suffix++;
        }
        $usedkeys[$key] = true;

        return $key;
    }

    /**
     * Returns a station title.
     *
     * @param \stdClass $item
     * @param \course_modinfo $modinfo
     * @return string
     */
    private function get_station_title(\stdClass $item, \course_modinfo $modinfo): string {
        $title = $this->clean_title((string)($item->title ?? ''));
        if ($title !== '') {
            return $title;
        }

        try {
            $cm = $modinfo->get_cm((int)$item->cmid);
            return $this->clean_title((string)$cm->name);
        } catch (\Throwable $exception) {
            return '';
        }
    }

    /**
     * Returns a container title.
     *
     * @param \stdClass $item
     * @param \course_modinfo $modinfo
     * @return string
     */
    private function get_container_title(\stdClass $item, \course_modinfo $modinfo): string {
        $title = $this->clean_title((string)($item->title ?? ''));
        if ($title !== '') {
            return $title;
        }

        if (!empty($item->sectionid)) {
            try {
                $section = $modinfo->get_section_info_by_id((int)$item->sectionid);
                if ($section) {
                    return $this->clean_title(get_section_name($modinfo->get_course(), $section));
                }
            } catch (\Throwable $exception) {
                return '';
            }
        }

        return '';
    }

    /**
     * Cleans a display title.
     *
     * @param string $title
     * @return string
     */
    private function clean_title(string $title): string {
        return trim(clean_param($title, PARAM_TEXT));
    }

    /**
     * Decodes item configdata.
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
     * Cleans a string list.
     *
     * @param mixed $values
     * @return string[]
     */
    private function clean_string_array($values): array {
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(static function($value): string {
            return clean_param((string)$value, PARAM_ALPHANUMEXT);
        }, $values), static function(string $value): bool {
            return $value !== '';
        }));
    }

    /**
     * Builds a source hash from portable source data.
     *
     * @param \stdClass $course
     * @param \stdClass $path
     * @param \stdClass[] $items
     * @return string
     */
    private function source_hash(\stdClass $course, \stdClass $path, array $items): string {
        $source = [
            'courseid' => (int)$course->id,
            'path' => [
                'id' => (int)$path->id,
                'userid' => (int)($path->userid ?? 0),
                'name' => (string)($path->name ?? ''),
                'description' => (string)($path->description ?? ''),
                'timemodified' => (int)($path->timemodified ?? 0),
            ],
            'items' => array_map([$this, 'source_item'], $items),
        ];

        return hash('sha256', json_encode($source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Returns portable source data for one item.
     *
     * @param \stdClass $item
     * @return array
     */
    private function source_item(\stdClass $item): array {
        return [
            'id' => (int)($item->id ?? 0),
            'parentid' => (int)($item->parentid ?? 0),
            'itemtype' => (string)($item->itemtype ?? ''),
            'cmid' => (int)($item->cmid ?? 0),
            'sectionid' => (int)($item->sectionid ?? 0),
            'title' => (string)($item->title ?? ''),
            'description' => (string)($item->description ?? ''),
            'availabilitymode' => (string)($item->availabilitymode ?? ''),
            'configdata' => (string)($item->configdata ?? ''),
            'sortorder' => (int)($item->sortorder ?? 0),
            'children' => array_map([$this, 'source_item'], $item->children ?? []),
        ];
    }
}
