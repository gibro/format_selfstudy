<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Converts and validates the authoring grid used by the learning path editor.
 */
class path_grid_service {

    /**
     * Builds path items from submitted grid JSON.
     *
     * @param string $gridjson
     * @param \stdClass[] $activities
     * @param \stdClass[] $sections
     * @return array
     */
    public function build_items_from_grid(string $gridjson, array $activities, array $sections = []): array {
        $decoded = json_decode($gridjson, true);
        if (!is_array($decoded)) {
            $decoded = $this->default_grid($activities);
        }
        $decoded = $this->normalise_milestone_alternatives($decoded);

        $activitymap = $this->activity_map($activities);
        $used = [];
        $items = [];

        foreach (($decoded['milestones'] ?? []) as $milestoneindex => $milestone) {
            if (!is_array($milestone)) {
                continue;
            }

            $rows = is_array($milestone['rows'] ?? null) ? $milestone['rows'] : [];
            $cleanrows = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $cells = [];
                foreach ($row as $cell) {
                    $cmid = is_array($cell) ? (int)($cell['cmid'] ?? 0) : 0;
                    if (!$cmid || empty($activitymap[$cmid]) || isset($used[$cmid])) {
                        continue;
                    }
                    $used[$cmid] = true;
                    $cells[] = $cmid;
                }
                if ($cells) {
                    $cleanrows[] = $cells;
                }
            }

            $title = $this->get_section_title_by_num((int)($milestone['sectionnum'] ?? 0), $sections);
            if ($title === '') {
                $title = $this->get_section_title_from_clean_rows($cleanrows, $activitymap);
            }
            if ($title === '') {
                $title = get_string('learningpathmilestone', 'format_selfstudy') . ' ' . ((int)$milestoneindex + 1);
            }

            $items[] = [
                'itemtype' => path_repository::ITEM_MILESTONE,
                'title' => $title,
                'description' => clean_param((string)($milestone['description'] ?? ''), PARAM_RAW),
                'configdata' => [
                    'milestonekey' => clean_param(
                        (string)($milestone['key'] ?? ('milestone-' . ((int)$milestoneindex + 1))),
                        PARAM_ALPHANUMEXT
                    ),
                    'sectionnum' => clean_param((int)($milestone['sectionnum'] ?? 0), PARAM_INT),
                    'alternativepeers' => $this->clean_string_array($milestone['alternativepeers'] ?? []),
                    'alternativegroup' => clean_param((string)($milestone['alternativegroup'] ?? ''), PARAM_TEXT),
                ],
                'sortorder' => count($items),
            ];

            foreach ($cleanrows as $row) {
                if (count($row) === 1) {
                    $items[] = [
                        'itemtype' => path_repository::ITEM_STATION,
                        'cmid' => (int)$row[0],
                        'sortorder' => count($items),
                    ];
                    continue;
                }

                $children = [];
                foreach ($row as $childindex => $cmid) {
                    $children[] = [
                        'itemtype' => path_repository::ITEM_STATION,
                        'cmid' => (int)$cmid,
                        'sortorder' => $childindex,
                    ];
                }
                $items[] = [
                    'itemtype' => path_repository::ITEM_ALTERNATIVE,
                    'title' => get_string('learningpathalternative', 'format_selfstudy'),
                    'sortorder' => count($items),
                    'children' => $children,
                ];
            }
        }

        return $items;
    }

    /**
     * Validates submitted grid JSON before publishing.
     *
     * @param string $gridjson
     * @param \stdClass[] $activities
     * @param \stdClass[] $sections
     * @return string[]
     */
    public function validate_grid_for_publish(string $gridjson, array $activities, array $sections = []): array {
        $decoded = json_decode($gridjson, true);
        if (!is_array($decoded)) {
            return [get_string('learningpathvalidationinvalidgrid', 'format_selfstudy')];
        }
        $decoded = $this->normalise_milestone_alternatives($decoded);

        $activitymap = $this->activity_map($activities);
        $errors = [];
        $validactivitycount = 0;
        $milestones = is_array($decoded['milestones'] ?? null) ? $decoded['milestones'] : [];
        if (!$milestones) {
            return [get_string('learningpathvalidationemptypath', 'format_selfstudy')];
        }

        foreach ($milestones as $milestone) {
            if (!is_array($milestone)) {
                continue;
            }
            $rows = is_array($milestone['rows'] ?? null) ? $milestone['rows'] : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach ($row as $cell) {
                    $cmid = is_array($cell) ? (int)($cell['cmid'] ?? 0) : 0;
                    if (!$cmid) {
                        continue;
                    }
                    if (empty($activitymap[$cmid])) {
                        $errors[] = get_string('learningpathvalidationmissingactivity', 'format_selfstudy', $cmid);
                        continue;
                    }
                    $validactivitycount++;
                }
            }
        }

        if ($validactivitycount === 0 && empty($errors)) {
            $errors[] = get_string('learningpathvalidationemptyactivities', 'format_selfstudy');
        }

        return array_values(array_unique($errors));
    }

    /**
     * Returns a default empty grid with one milestone per usable course section.
     *
     * @param \stdClass[] $activities
     * @return array
     */
    private function default_grid(array $activities = []): array {
        $sections = [];
        foreach ($activities as $activity) {
            $sectionnum = (int)($activity->sectionnum ?? 0);
            if ($sectionnum <= 0 || isset($sections[$sectionnum])) {
                continue;
            }
            $sections[$sectionnum] = (string)($activity->sectionname ?? '');
        }

        if (!$sections) {
            return [
                'milestones' => [[
                    'title' => get_string('learningpathmilestone', 'format_selfstudy'),
                    'description' => '',
                    'key' => 'milestone-1',
                    'sectionnum' => 0,
                    'alternativegroup' => '',
                    'alternativepeers' => [],
                    'rows' => [[]],
                ]],
            ];
        }

        ksort($sections);
        $milestones = [];
        foreach ($sections as $sectionnum => $sectionname) {
            $milestones[] = [
                'key' => 'section-' . (int)$sectionnum,
                'title' => $sectionname !== '' ? $sectionname : get_string('learningpathmilestone', 'format_selfstudy'),
                'description' => '',
                'sectionnum' => (int)$sectionnum,
                'alternativegroup' => '',
                'alternativepeers' => [],
                'rows' => [[]],
            ];
        }

        return ['milestones' => $milestones];
    }

    /**
     * Converts legacy alternative groups into peer selections and keeps selections symmetric.
     *
     * @param array $grid
     * @return array
     */
    private function normalise_milestone_alternatives(array $grid): array {
        if (!is_array($grid['milestones'] ?? null)) {
            $grid['milestones'] = [];
        }

        $keys = [];
        $groups = [];
        foreach ($grid['milestones'] as $index => &$milestone) {
            if (!is_array($milestone)) {
                $milestone = [];
            }
            $key = clean_param((string)($milestone['key'] ?? ''), PARAM_ALPHANUMEXT);
            if ($key === '' || isset($keys[$key])) {
                $key = 'milestone-' . ((int)$index + 1);
            }
            $milestone['key'] = $key;
            $keys[$key] = true;

            $group = trim((string)($milestone['alternativegroup'] ?? ''));
            if ($group !== '') {
                $groups[$group][] = $key;
            }
            $milestone['alternativepeers'] = $this->clean_string_array($milestone['alternativepeers'] ?? []);
        }
        unset($milestone);

        foreach ($groups as $groupkeys) {
            if (count($groupkeys) < 2) {
                continue;
            }
            foreach ($grid['milestones'] as &$milestone) {
                if (!in_array($milestone['key'], $groupkeys, true)) {
                    continue;
                }
                $milestone['alternativepeers'] = array_values(array_unique(array_merge(
                    $milestone['alternativepeers'],
                    array_values(array_diff($groupkeys, [$milestone['key']]))
                )));
            }
            unset($milestone);
        }

        $validkeys = array_keys($keys);
        foreach ($grid['milestones'] as &$milestone) {
            $milestone['alternativepeers'] = array_values(array_filter($milestone['alternativepeers'],
                static function(string $peerkey) use ($validkeys, $milestone): bool {
                    return $peerkey !== $milestone['key'] && in_array($peerkey, $validkeys, true);
                }));
        }
        unset($milestone);

        foreach ($grid['milestones'] as $milestone) {
            foreach ($milestone['alternativepeers'] as $peerkey) {
                foreach ($grid['milestones'] as &$candidate) {
                    if ($candidate['key'] !== $peerkey) {
                        continue;
                    }
                    $candidate['alternativepeers'][] = $milestone['key'];
                    $candidate['alternativepeers'] = array_values(array_unique($candidate['alternativepeers']));
                }
                unset($candidate);
            }
        }

        return $grid;
    }

    /**
     * Cleans an array of string identifiers.
     *
     * @param mixed $values
     * @return string[]
     */
    private function clean_string_array($values): array {
        if (!is_array($values)) {
            return [];
        }

        $cleaned = [];
        foreach ($values as $value) {
            $value = clean_param((string)$value, PARAM_ALPHANUMEXT);
            if ($value !== '') {
                $cleaned[] = $value;
            }
        }

        return array_values(array_unique($cleaned));
    }

    /**
     * Returns an activity map keyed by course module id.
     *
     * @param \stdClass[] $activities
     * @return \stdClass[]
     */
    private function activity_map(array $activities): array {
        $map = [];
        foreach ($activities as $activity) {
            $map[(int)$activity->id] = $activity;
        }

        return $map;
    }

    /**
     * Returns the Moodle section title for the first activity found in cleaned submitted rows.
     *
     * @param array $rows
     * @param \stdClass[] $activitymap
     * @return string
     */
    private function get_section_title_from_clean_rows(array $rows, array $activitymap): string {
        foreach ($rows as $row) {
            foreach ($row as $cmid) {
                $cmid = (int)$cmid;
                if ($cmid && !empty($activitymap[$cmid]->sectionname)) {
                    return (string)$activitymap[$cmid]->sectionname;
                }
            }
        }

        return '';
    }

    /**
     * Returns a Moodle section title by section number.
     *
     * @param int $sectionnum
     * @param \stdClass[] $sections
     * @return string
     */
    private function get_section_title_by_num(int $sectionnum, array $sections): string {
        if ($sectionnum <= 0 || empty($sections[$sectionnum])) {
            return '';
        }

        return (string)($sections[$sectionnum]->name ?? '');
    }
}
