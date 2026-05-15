<?php
// This file is part of Moodle - http://moodle.org/

namespace selfstudyexperience_learningmap;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds a neutral map model from the active learner path.
 */
class map_builder {

    /**
     * Builds a learner map model from format_selfstudy's base view.
     *
     * @param \stdClass $course
     * @param \stdClass $baseview
     * @param \stdClass $config
     * @return \stdClass
     */
    public function build(\stdClass $course, \stdClass $baseview, \stdClass $config): \stdClass {
        $nodes = [];
        $connections = [];
        $previouskey = '';
        $currentkey = '';

        foreach (array_values((array)($baseview->outline ?? [])) as $index => $entry) {
            $node = $this->outline_entry_to_node($entry, $index, $config);
            $nodes[] = $node;

            if ($previouskey !== '') {
                $connections[] = (object)[
                    'from' => $previouskey,
                    'to' => $node->key,
                    'state' => $node->status === 'locked' ? 'locked' : 'open',
                ];
            }
            $previouskey = $node->key;

            if ($currentkey === '' && in_array($node->status, ['recommended', 'current', 'started', 'open'], true)) {
                $currentkey = $node->key;
            }
        }

        return (object)[
            'courseid' => (int)$course->id,
            'pathid' => (int)($baseview->path->id ?? 0),
            'revision' => (int)($baseview->revision ?? 0),
            'source' => (string)($baseview->source ?? ''),
            'theme' => (string)($config->theme ?? 'adventure'),
            'avatar' => !isset($config->avatarenabled) || !empty($config->avatarenabled),
            'route' => !isset($config->routeenabled) || !empty($config->routeenabled),
            'milestonebadges' => !isset($config->milestonebadgesenabled) || !empty($config->milestonebadgesenabled),
            'currentkey' => $currentkey,
            'nodes' => $nodes,
            'connections' => $connections,
        ];
    }

    /**
     * Converts one flattened outline entry to a map node.
     *
     * @param \stdClass $entry
     * @param int $index
     * @param \stdClass $config
     * @return \stdClass
     */
    private function outline_entry_to_node(\stdClass $entry, int $index, \stdClass $config): \stdClass {
        $type = (string)($entry->type ?? 'station');
        $cmid = (int)($entry->cmid ?? 0);
        $status = (string)($entry->status ?? 'open');
        $level = max(0, (int)($entry->level ?? 0));

        return (object)[
            'key' => $this->node_key($entry, $index),
            'type' => $type,
            'cmid' => $cmid,
            'title' => (string)($entry->title ?? ''),
            'status' => $status,
            'level' => $level,
            'url' => (string)($entry->url ?? ''),
            'availableinfo' => (string)($entry->availableinfo ?? ''),
            'gamestate' => $this->game_state($type, $status),
            'x' => 12 + (($index % 4) * 24) + ($level * 4),
            'y' => 12 + (floor($index / 4) * 18),
        ];
    }

    /**
     * Returns a stable map node key.
     *
     * @param \stdClass $entry
     * @param int $index
     * @return string
     */
    private function node_key(\stdClass $entry, int $index): string {
        if (!empty($entry->cmid)) {
            return 'cm-' . (int)$entry->cmid;
        }
        if (!empty($entry->id)) {
            return clean_param((string)($entry->type ?? 'node'), PARAM_ALPHANUMEXT) . '-' . (int)$entry->id;
        }
        return 'node-' . $index;
    }

    /**
     * Maps path status to playful display state.
     *
     * @param string $type
     * @param string $status
     * @return string
     */
    private function game_state(string $type, string $status): string {
        if ($type === 'milestone') {
            return $status === 'complete' ? 'badge-earned' : 'checkpoint';
        }

        $map = [
            'complete' => 'discovered',
            'recommended' => 'next',
            'current' => 'next',
            'started' => 'in-progress',
            'review' => 'challenge',
            'locked' => 'fog',
            'missing' => 'broken',
        ];

        return $map[$status] ?? 'available';
    }
}
