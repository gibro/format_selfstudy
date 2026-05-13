<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds selfstudy availability rules from a runtime snapshot.
 */
class availability_rule_builder {

    /**
     * Builds all target rules from a decoded runtime snapshot.
     *
     * @param array $snapshot
     * @return \stdClass[]
     */
    public function build_rules(array $snapshot): array {
        $nodes = $this->index_nodes($snapshot['nodes'] ?? []);
        $root = $this->clean_key_list($snapshot['root'] ?? []);
        if (!$nodes || !$root) {
            return [];
        }

        $rules = [];
        $containers = array_values(array_filter(array_map(static function(string $key) use ($nodes) {
            $node = $nodes[$key] ?? null;
            if (!$node || !in_array((string)($node['type'] ?? ''), [
                    path_repository::ITEM_MILESTONE,
                    path_repository::ITEM_SEQUENCE,
                ], true)) {
                return null;
            }
            return $node;
        }, $root)));

        if ($containers) {
            foreach ($containers as $container) {
                foreach ($this->build_sequential_rules($container['children'] ?? [], $nodes,
                        (string)($container['title'] ?? $container['key'])) as $rule) {
                    $rules[] = $rule;
                }
            }

            foreach ($this->build_between_container_rules($containers, $nodes) as $rule) {
                $rules[] = $rule;
            }
            return $rules;
        }

        return $this->build_sequential_rules($root, $nodes, '');
    }

    /**
     * Builds rules between adjacent rows in one container.
     *
     * @param string[] $keys
     * @param array $nodes
     * @param string $milestonelabel
     * @return \stdClass[]
     */
    private function build_sequential_rules(array $keys, array $nodes, string $milestonelabel): array {
        $rules = [];
        $keys = $this->clean_key_list($keys);
        for ($index = 1; $index < count($keys); $index++) {
            $source = $nodes[$keys[$index - 1]] ?? null;
            $target = $nodes[$keys[$index]] ?? null;
            if (!$source || !$target) {
                continue;
            }
            foreach ($this->target_station_nodes($target, $nodes) as $targetstation) {
                $context = $milestonelabel === '' ?
                    get_string('learningpathsyncrulelegacy', 'format_selfstudy') :
                    get_string('learningpathsyncrulewithinmilestone', 'format_selfstudy', (object)[
                        'milestone' => $milestonelabel,
                        'row' => $index + 1,
                    ]);
                $rules[] = $this->rule_record($source, $targetstation, $nodes, $context);
            }
        }

        return $rules;
    }

    /**
     * Builds transition rules between milestone or sequence containers.
     *
     * @param array $containers
     * @param array $nodes
     * @return \stdClass[]
     */
    private function build_between_container_rules(array $containers, array $nodes): array {
        $groups = $this->build_container_groups($containers);
        $rules = [];
        for ($index = 1; $index < count($groups); $index++) {
            $source = $this->terminal_group_rule($groups[$index - 1], $nodes);
            if (!$source) {
                continue;
            }
            foreach ($groups[$index] as $container) {
                $firstkey = $this->first_child_key($container, $nodes);
                if ($firstkey === '') {
                    continue;
                }
                foreach ($this->target_station_nodes($nodes[$firstkey], $nodes) as $targetstation) {
                    $rules[] = $this->rule_record($source, $targetstation, $nodes,
                        get_string('learningpathsyncrulebetweenmilestones', 'format_selfstudy',
                            (string)($container['title'] ?? $container['key'])));
                }
            }
        }

        return $rules;
    }

    /**
     * Builds one diagnosis-ready rule record.
     *
     * @param array $source
     * @param array $target
     * @param array $nodes
     * @param string $context
     * @return \stdClass
     */
    private function rule_record(array $source, array $target, array $nodes, string $context): \stdClass {
        return (object)[
            'targetkey' => (string)$target['key'],
            'targetcmid' => (int)($target['cmid'] ?? 0),
            'rule' => $this->source_rule($source, $nodes),
            'sourcekeys' => $this->source_keys($source, $nodes),
            'context' => $context,
        ];
    }

    /**
     * Builds the internal rule node for a source snapshot node.
     *
     * @param array $source
     * @param array $nodes
     * @return array
     */
    private function source_rule(array $source, array $nodes): array {
        if (($source['type'] ?? '') === path_repository::ITEM_STATION && !empty($source['cmid'])) {
            return [
                'type' => 'completion',
                'cmid' => (int)$source['cmid'],
            ];
        }

        $children = [];
        foreach ($this->source_station_nodes($source, $nodes) as $child) {
            $children[] = [
                'type' => 'completion',
                'cmid' => (int)$child['cmid'],
            ];
        }

        return [
            'type' => 'any_of',
            'rules' => $children,
        ];
    }

    /**
     * Returns source node keys represented by one source node.
     *
     * @param array $source
     * @param array $nodes
     * @return string[]
     */
    private function source_keys(array $source, array $nodes): array {
        if (($source['type'] ?? '') === path_repository::ITEM_STATION) {
            return [(string)$source['key']];
        }

        return array_values(array_map(static function(array $node): string {
            return (string)$node['key'];
        }, $this->source_station_nodes($source, $nodes)));
    }

    /**
     * Returns station children that can be targets.
     *
     * @param array $node
     * @param array $nodes
     * @return array
     */
    private function target_station_nodes(array $node, array $nodes): array {
        if (($node['type'] ?? '') === path_repository::ITEM_STATION && !empty($node['cmid'])) {
            return [$node];
        }
        if (($node['type'] ?? '') === path_repository::ITEM_ALTERNATIVE) {
            return $this->source_station_nodes($node, $nodes);
        }

        return [];
    }

    /**
     * Returns station nodes represented by a source node.
     *
     * @param array $node
     * @param array $nodes
     * @return array
     */
    private function source_station_nodes(array $node, array $nodes): array {
        $stations = [];
        foreach ($this->clean_key_list($node['children'] ?? []) as $key) {
            $child = $nodes[$key] ?? null;
            if ($child && ($child['type'] ?? '') === path_repository::ITEM_STATION && !empty($child['cmid'])) {
                $stations[] = $child;
            }
        }

        return $stations;
    }

    /**
     * Groups containers connected through alternativepeers.
     *
     * @param array $containers
     * @return array
     */
    private function build_container_groups(array $containers): array {
        $bykey = [];
        foreach ($containers as $index => $container) {
            $key = $this->peer_key($container);
            if ($key !== '') {
                $bykey[$key] = $index;
            }
        }

        $visited = [];
        $groups = [];
        foreach ($containers as $index => $container) {
            if (!empty($visited[$index])) {
                continue;
            }
            $indexes = [];
            $this->collect_group_indexes($index, $containers, $bykey, $visited, $indexes);
            sort($indexes);
            $groups[] = array_map(static function(int $groupindex) use ($containers): array {
                return $containers[$groupindex];
            }, $indexes);
        }

        return $groups;
    }

    /**
     * Collects connected container indexes.
     *
     * @param int $index
     * @param array $containers
     * @param array $bykey
     * @param array $visited
     * @param int[] $indexes
     */
    private function collect_group_indexes(int $index, array $containers, array $bykey, array &$visited,
            array &$indexes): void {
        if (!empty($visited[$index])) {
            return;
        }
        $visited[$index] = true;
        $indexes[] = $index;
        foreach ($this->clean_key_list($containers[$index]['alternativepeers'] ?? []) as $peerkey) {
            if (isset($bykey[$peerkey])) {
                $this->collect_group_indexes($bykey[$peerkey], $containers, $bykey, $visited, $indexes);
            }
        }
    }

    /**
     * Builds a source rule node from the terminal children of a container group.
     *
     * @param array $group
     * @param array $nodes
     * @return array|null
     */
    private function terminal_group_rule(array $group, array $nodes): ?array {
        $stations = [];
        foreach ($group as $container) {
            $children = $this->clean_key_list($container['children'] ?? []);
            if (!$children) {
                continue;
            }
            $terminal = $nodes[$children[count($children) - 1]] ?? null;
            if (!$terminal) {
                continue;
            }
            foreach ($this->target_station_nodes($terminal, $nodes) as $station) {
                $stations[] = $station;
            }
        }

        if (!$stations) {
            return null;
        }
        if (count($stations) === 1) {
            return $stations[0];
        }

        return [
            'key' => 'previous-milestone-group',
            'type' => path_repository::ITEM_ALTERNATIVE,
            'title' => get_string('learningpathsyncpreviousmilestonegroup', 'format_selfstudy'),
            'children' => array_values(array_map(static function(array $station): string {
                return (string)$station['key'];
            }, $stations)),
        ];
    }

    /**
     * Returns the first usable child key of a container.
     *
     * @param array $container
     * @param array $nodes
     * @return string
     */
    private function first_child_key(array $container, array $nodes): string {
        foreach ($this->clean_key_list($container['children'] ?? []) as $key) {
            if (isset($nodes[$key])) {
                return $key;
            }
        }

        return '';
    }

    /**
     * Indexes snapshot nodes by key.
     *
     * @param mixed $nodes
     * @return array
     */
    private function index_nodes($nodes): array {
        if (!is_array($nodes)) {
            return [];
        }

        $indexed = [];
        foreach ($nodes as $node) {
            if (is_array($node) && !empty($node['key'])) {
                $indexed[(string)$node['key']] = $node;
            }
        }

        return $indexed;
    }

    /**
     * Returns a stable peer key for a container node.
     *
     * @param array $container
     * @return string
     */
    private function peer_key(array $container): string {
        $key = (string)($container['key'] ?? '');
        if (strpos($key, 'milestone-') === 0) {
            return substr($key, strlen('milestone-'));
        }

        return $key;
    }

    /**
     * Cleans a key list.
     *
     * @param mixed $keys
     * @return string[]
     */
    private function clean_key_list($keys): array {
        if (!is_array($keys)) {
            return [];
        }

        return array_values(array_filter(array_map(static function($key): string {
            return clean_param((string)$key, PARAM_ALPHANUMEXT);
        }, $keys), static function(string $key): bool {
            return $key !== '';
        }));
    }
}
