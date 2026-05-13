<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Imports and exports learning paths as reusable JSON structures.
 */
class path_transfer {

    /** @var path_repository */
    private $repository;

    /** @var array Activity reference resolution cache. */
    private $cmresolutioncache = [];

    /**
     * Constructor.
     *
     * @param path_repository|null $repository
     */
    public function __construct(?path_repository $repository = null) {
        $this->repository = $repository ?? new path_repository();
    }

    /**
     * Builds an export payload for one global learning path.
     *
     * @param \stdClass $course
     * @param int $pathid
     * @return array
     */
    public function export_path(\stdClass $course, int $pathid): array {
        $path = $this->repository->get_path($pathid);
        if (!$path || (int)$path->courseid !== (int)$course->id || (int)($path->userid ?? 0) !== 0) {
            throw new \moodle_exception('invalidrecord', 'error');
        }

        return [
            'schema' => 'format_selfstudy_path',
            'version' => 2,
            'sourcecourse' => [
                'id' => (int)$course->id,
                'fullname' => format_string($course->fullname, true),
                'shortname' => format_string($course->shortname, true),
            ],
            'path' => [
                'name' => $path->name,
                'description' => $path->description,
                'descriptionformat' => (int)$path->descriptionformat,
                'imageurl' => $path->imageurl,
                'icon' => $path->icon,
                'enabled' => (int)$path->enabled,
                'grid' => $this->export_grid($course, $this->repository->get_path_tree($pathid)),
                'items' => $this->export_items($course, $this->repository->get_path_tree($pathid)),
            ],
        ];
    }

    /**
     * Imports a path payload into a course and returns the new path id.
     *
     * @param \stdClass $course
     * @param array $payload
     * @return int
     */
    public function import_path(\stdClass $course, array $payload): int {
        if (($payload['schema'] ?? '') !== 'format_selfstudy_path' || empty($payload['path']) || !is_array($payload['path'])) {
            throw new \moodle_exception('learningpathimportinvalid', 'format_selfstudy');
        }

        $issues = $this->validate_import($course, $payload);
        if (!empty($issues->missing) || !empty($issues->ambiguous) || !empty($issues->invalid)) {
            throw new \moodle_exception('learningpathimportactivityresolutionfailed', 'format_selfstudy', '',
                $this->format_import_issues($issues));
        }

        $pathdata = $payload['path'];
        $items = !empty($pathdata['grid']) && is_array($pathdata['grid']) ?
            $this->import_grid($course, $pathdata['grid']) :
            $this->import_items($course, $pathdata['items'] ?? []);
        if (!$items) {
            throw new \moodle_exception('learningpathimportempty', 'format_selfstudy');
        }

        $pathid = $this->repository->create_path((int)$course->id, [
            'name' => $this->get_import_name((int)$course->id, (string)($pathdata['name'] ?? '')),
            'description' => (string)($pathdata['description'] ?? ''),
            'descriptionformat' => (int)($pathdata['descriptionformat'] ?? FORMAT_HTML),
            'imageurl' => (string)($pathdata['imageurl'] ?? ''),
            'icon' => (string)($pathdata['icon'] ?? ''),
            'enabled' => (int)($pathdata['enabled'] ?? 1),
            'userid' => 0,
        ]);
        $this->repository->replace_path_items($pathid, $items);

        return $pathid;
    }

    /**
     * Decodes submitted JSON.
     *
     * @param string $json
     * @return array
     */
    public function decode_json(string $json): array {
        $payload = json_decode($json, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('learningpathimportinvalidjson', 'format_selfstudy');
        }
        return $payload;
    }

    /**
     * Validates whether all exported activity references can be resolved safely.
     *
     * @param \stdClass $course
     * @param array $payload
     * @return \stdClass
     */
    public function validate_import(\stdClass $course, array $payload): \stdClass {
        if (($payload['schema'] ?? '') !== 'format_selfstudy_path' || empty($payload['path']) || !is_array($payload['path'])) {
            throw new \moodle_exception('learningpathimportinvalid', 'format_selfstudy');
        }

        $pathdata = $payload['path'];
        $refs = !empty($pathdata['grid']) && is_array($pathdata['grid']) ?
            $this->collect_grid_cmrefs($pathdata['grid']) :
            $this->collect_item_cmrefs($pathdata['items'] ?? []);
        $issues = (object)[
            'missing' => [],
            'ambiguous' => [],
            'invalid' => [],
        ];

        foreach ($refs as $refinfo) {
            if (empty($refinfo['ref']) || !is_array($refinfo['ref'])) {
                $issues->invalid[] = $refinfo['label'];
                continue;
            }
            $match = $this->resolve_cm_match($course, $refinfo['ref']);
            if ($match->status === 'missing') {
                $issues->missing[] = $refinfo['label'];
            } else if ($match->status === 'ambiguous') {
                $issues->ambiguous[] = $refinfo['label'];
            } else if ($match->status !== 'ok') {
                $issues->invalid[] = $refinfo['label'];
            }
        }

        $issues->missing = array_values(array_unique($issues->missing));
        $issues->ambiguous = array_values(array_unique($issues->ambiguous));
        $issues->invalid = array_values(array_unique($issues->invalid));

        return $issues;
    }

    /**
     * Exports the editor grid representation.
     *
     * @param \stdClass $course
     * @param \stdClass[] $items
     * @return array
     */
    private function export_grid(\stdClass $course, array $items): array {
        $milestones = [];
        $current = null;
        foreach ($items as $item) {
            if ($item->itemtype === path_repository::ITEM_MILESTONE) {
                if ($current) {
                    $milestones[] = $current;
                }
                $config = $this->decode_configdata((string)($item->configdata ?? ''));
                $current = [
                    'key' => (string)($config['milestonekey'] ?? ('item-' . (int)$item->id)),
                    'title' => (string)$item->title,
                    'description' => (string)$item->description,
                    'sectionnum' => (int)($config['sectionnum'] ?? 0),
                    'alternativegroup' => (string)($config['alternativegroup'] ?? ''),
                    'alternativepeers' => array_values(array_filter((array)($config['alternativepeers'] ?? []))),
                    'rows' => [],
                ];
                continue;
            }
            if (!$current) {
                continue;
            }
            if ($item->itemtype === path_repository::ITEM_STATION && !empty($item->cmid)) {
                $cmref = $this->export_cm_reference($course, (int)$item->cmid);
                if ($cmref) {
                    $current['rows'][] = [['cmref' => $cmref]];
                }
            } else if ($item->itemtype === path_repository::ITEM_ALTERNATIVE) {
                $row = [];
                foreach ($item->children ?? [] as $child) {
                    if ($child->itemtype !== path_repository::ITEM_STATION || empty($child->cmid)) {
                        continue;
                    }
                    $cmref = $this->export_cm_reference($course, (int)$child->cmid);
                    if ($cmref) {
                        $row[] = ['cmref' => $cmref];
                    }
                }
                if ($row) {
                    $current['rows'][] = $row;
                }
            }
        }
        if ($current) {
            $milestones[] = $current;
        }

        return ['milestones' => $milestones];
    }

    /**
     * Exports a branch of path items.
     *
     * @param \stdClass $course
     * @param \stdClass[] $items
     * @return array
     */
    private function export_items(\stdClass $course, array $items): array {
        $exported = [];
        $modinfo = get_fast_modinfo($course);

        foreach ($items as $item) {
            $record = [
                'itemtype' => $item->itemtype,
                'title' => $item->title,
                'description' => $item->description,
                'descriptionformat' => (int)$item->descriptionformat,
                'availabilitymode' => $item->availabilitymode,
                'configdata' => $item->configdata,
                'sortorder' => (int)$item->sortorder,
                'children' => $this->export_items($course, $item->children ?? []),
            ];

            if (!empty($item->cmid)) {
                $cmref = $this->export_cm_reference($course, (int)$item->cmid, $modinfo);
                if (!$cmref) {
                    continue;
                }
                $record['cmref'] = $cmref;
            }
            if (!empty($item->sectionid)) {
                $section = $modinfo->get_section_info_by_id((int)$item->sectionid, IGNORE_MISSING);
                if ($section) {
                    $record['sectionref'] = [
                        'oldsectionid' => (int)$item->sectionid,
                        'sectionnum' => (int)$section->section,
                        'name' => get_section_name($course, $section),
                    ];
                }
            }

            $exported[] = $record;
        }

        return $exported;
    }

    /**
     * Imports a branch of path items.
     *
     * @param \stdClass $course
     * @param array $items
     * @return array
     */
    private function import_items(\stdClass $course, array $items): array {
        $imported = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemtype = clean_param($item['itemtype'] ?? '', PARAM_ALPHANUMEXT);
            if ($itemtype === '') {
                continue;
            }

            $children = $this->import_items($course, $item['children'] ?? []);
            $record = [
                'itemtype' => $itemtype,
                'title' => (string)($item['title'] ?? ''),
                'description' => (string)($item['description'] ?? ''),
                'descriptionformat' => (int)($item['descriptionformat'] ?? FORMAT_HTML),
                'availabilitymode' => (string)($item['availabilitymode'] ?? path_repository::AVAILABILITY_SHOW),
                'configdata' => (string)($item['configdata'] ?? ''),
                'sortorder' => count($imported),
                'children' => $children,
            ];

            if ($itemtype === path_repository::ITEM_STATION) {
                $cmid = $this->resolve_cm($course, $item['cmref'] ?? []);
                $record['cmid'] = $cmid;
            } else if ($itemtype === path_repository::ITEM_SEQUENCE) {
                $record['sectionid'] = $this->resolve_section($course, $item['sectionref'] ?? []);
                if (!$children && empty($record['sectionid'])) {
                    continue;
                }
            } else if ($itemtype === path_repository::ITEM_ALTERNATIVE && !$children) {
                continue;
            }

            $imported[] = $record;
        }

        return $imported;
    }

    /**
     * Imports editor grid data into path items.
     *
     * @param \stdClass $course
     * @param array $grid
     * @return array
     */
    private function import_grid(\stdClass $course, array $grid): array {
        $items = [];
        foreach (($grid['milestones'] ?? []) as $milestoneindex => $milestone) {
            if (!is_array($milestone)) {
                continue;
            }
            $rows = [];
            foreach (($milestone['rows'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $cmids = [];
                foreach ($row as $cell) {
                    $cmid = $this->resolve_cm($course, is_array($cell) ? ($cell['cmref'] ?? []) : []);
                    $cmids[] = $cmid;
                }
                if ($cmids) {
                    $rows[] = array_values(array_unique($cmids));
                }
            }
            if (!$rows) {
                continue;
            }

            $items[] = [
                'itemtype' => path_repository::ITEM_MILESTONE,
                'title' => (string)($milestone['title'] ?? get_string('learningpathmilestone', 'format_selfstudy')),
                'description' => (string)($milestone['description'] ?? ''),
                'configdata' => [
                    'milestonekey' => clean_param((string)($milestone['key'] ?? ('imported-' . ($milestoneindex + 1))),
                        PARAM_ALPHANUMEXT),
                    'sectionnum' => (int)($milestone['sectionnum'] ?? 0),
                    'alternativegroup' => clean_param((string)($milestone['alternativegroup'] ?? ''), PARAM_TEXT),
                    'alternativepeers' => $this->clean_string_array($milestone['alternativepeers'] ?? []),
                ],
                'sortorder' => count($items),
            ];

            foreach ($rows as $row) {
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
     * Resolves an exported activity reference in the target course.
     *
     * @param \stdClass $course
     * @param array $ref
     * @return int
     */
    private function resolve_cm(\stdClass $course, array $ref): int {
        $match = $this->resolve_cm_match($course, $ref);
        if ($match->status !== 'ok' || empty($match->cmid)) {
            throw new \moodle_exception('learningpathimportactivityresolutionfailed', 'format_selfstudy', '',
                $this->format_activity_ref_label($ref));
        }

        return (int)$match->cmid;
    }

    /**
     * Resolves an exported activity reference and reports whether the match is safe.
     *
     * @param \stdClass $course
     * @param array $ref
     * @return \stdClass
     */
    private function resolve_cm_match(\stdClass $course, array $ref): \stdClass {
        $cachekey = (int)$course->id . ':' . json_encode($ref);
        if (array_key_exists($cachekey, $this->cmresolutioncache)) {
            return $this->cmresolutioncache[$cachekey];
        }

        $modinfo = get_fast_modinfo($course);
        $modname = (string)($ref['modname'] ?? '');
        $name = (string)($ref['name'] ?? '');
        $idnumber = trim((string)($ref['idnumber'] ?? ''));
        $sectionnum = array_key_exists('sectionnum', $ref) ? (int)$ref['sectionnum'] : null;

        if ($modname === '' || $name === '') {
            return $this->cmresolutioncache[$cachekey] = (object)['status' => 'invalid', 'cmid' => 0];
        }

        if (!empty($ref['oldcmid'])) {
            try {
                $cm = $modinfo->get_cm((int)$ref['oldcmid']);
                if ($this->cm_is_import_candidate($cm, $modname) &&
                        format_string($cm->name, true) === $name) {
                    return $this->cmresolutioncache[$cachekey] = (object)['status' => 'ok', 'cmid' => (int)$cm->id];
                }
            } catch (\Throwable $exception) {
                // Continue with stable reference matching below.
            }
        }

        $candidates = [];

        foreach ($modinfo->cms as $cm) {
            if (!$this->cm_is_import_candidate($cm, $modname)) {
                continue;
            }
            if (format_string($cm->name, true) !== $name) {
                continue;
            }
            $candidates[] = $cm;
        }

        if ($idnumber !== '') {
            $idnumbermatches = array_values(array_filter($candidates, static function(\cm_info $cm) use ($idnumber): bool {
                return trim((string)($cm->idnumber ?? '')) === $idnumber;
            }));
            if (count($idnumbermatches) === 1) {
                return $this->cmresolutioncache[$cachekey] =
                    (object)['status' => 'ok', 'cmid' => (int)$idnumbermatches[0]->id];
            }
            if (count($idnumbermatches) > 1) {
                return $this->cmresolutioncache[$cachekey] = (object)['status' => 'ambiguous', 'cmid' => 0];
            }
        }

        if (count($candidates) === 1) {
            return $this->cmresolutioncache[$cachekey] = (object)['status' => 'ok', 'cmid' => (int)$candidates[0]->id];
        }

        if (count($candidates) > 1 && $sectionnum !== null) {
            $sectionmatches = array_values(array_filter($candidates, static function(\cm_info $cm) use ($sectionnum): bool {
                return (int)$cm->sectionnum === $sectionnum;
            }));
            if (count($sectionmatches) === 1) {
                return $this->cmresolutioncache[$cachekey] =
                    (object)['status' => 'ok', 'cmid' => (int)$sectionmatches[0]->id];
            }
        }

        return $this->cmresolutioncache[$cachekey] =
            (object)['status' => $candidates ? 'ambiguous' : 'missing', 'cmid' => 0];
    }

    /**
     * Resolves an exported section reference in the target course.
     *
     * @param \stdClass $course
     * @param array $ref
     * @return int
     */
    private function resolve_section(\stdClass $course, array $ref): int {
        $modinfo = get_fast_modinfo($course);
        $name = (string)($ref['name'] ?? '');
        $sectionnum = isset($ref['sectionnum']) ? (int)$ref['sectionnum'] : null;

        foreach ($modinfo->get_section_info_all() as $section) {
            if ($name !== '' && get_section_name($course, $section) === $name) {
                return (int)$section->id;
            }
            if ($sectionnum !== null && (int)$section->section === $sectionnum) {
                return (int)$section->id;
            }
        }

        return 0;
    }

    /**
     * Exports one course module reference.
     *
     * @param \stdClass $course
     * @param int $cmid
     * @param \course_modinfo|null $modinfo
     * @return array|null
     */
    private function export_cm_reference(\stdClass $course, int $cmid, ?\course_modinfo $modinfo = null): ?array {
        $modinfo = $modinfo ?? get_fast_modinfo($course);
        try {
            $cm = $modinfo->get_cm($cmid);
        } catch (\moodle_exception $exception) {
            return null;
        }

        return [
            'oldcmid' => $cmid,
            'modname' => $cm->modname,
            'name' => format_string($cm->name, true),
            'idnumber' => (string)($cm->idnumber ?? ''),
            'sectionnum' => (int)$cm->sectionnum,
        ];
    }

    /**
     * Checks whether a target course module can be used for imported path stations.
     *
     * @param \cm_info $cm
     * @param string $modname
     * @return bool
     */
    private function cm_is_import_candidate(\cm_info $cm, string $modname): bool {
        if (!activity_filter::is_learning_station($cm)) {
            return false;
        }

        return $modname === '' || $cm->modname === $modname;
    }

    /**
     * Collects activity references from an exported item tree.
     *
     * @param array $items
     * @param string $trail
     * @return array
     */
    private function collect_item_cmrefs(array $items, string $trail = ''): array {
        $refs = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = $trail . '/' . clean_param((string)($item['itemtype'] ?? 'item'), PARAM_ALPHANUMEXT) .
                '#' . ($index + 1);
            if (($item['itemtype'] ?? '') === path_repository::ITEM_STATION) {
                $refs[] = [
                    'ref' => is_array($item['cmref'] ?? null) ? $item['cmref'] : [],
                    'label' => $this->format_activity_ref_label(is_array($item['cmref'] ?? null) ? $item['cmref'] : []) .
                        ' (' . trim($label, '/') . ')',
                ];
            }
            $refs = array_merge($refs, $this->collect_item_cmrefs($item['children'] ?? [], $label));
        }

        return $refs;
    }

    /**
     * Collects activity references from exported editor grid data.
     *
     * @param array $grid
     * @return array
     */
    private function collect_grid_cmrefs(array $grid): array {
        $refs = [];
        foreach (($grid['milestones'] ?? []) as $milestoneindex => $milestone) {
            if (!is_array($milestone)) {
                continue;
            }
            $milestonetitle = trim((string)($milestone['title'] ?? ''));
            $milestonelabel = $milestonetitle !== '' ? $milestonetitle :
                get_string('learningpathmilestone', 'format_selfstudy') . ' ' . ($milestoneindex + 1);
            foreach (($milestone['rows'] ?? []) as $rowindex => $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach ($row as $cellindex => $cell) {
                    $ref = is_array($cell) && is_array($cell['cmref'] ?? null) ? $cell['cmref'] : [];
                    $refs[] = [
                        'ref' => $ref,
                        'label' => $this->format_activity_ref_label($ref) . ' (' . $milestonelabel . ', ' .
                            get_string('learningpathrow', 'format_selfstudy', $rowindex + 1) . ', ' .
                            get_string('learningpathcolumn', 'format_selfstudy', $cellindex + 1) . ')',
                    ];
                }
            }
        }

        return $refs;
    }

    /**
     * Formats activity resolution issues for a Moodle exception.
     *
     * @param \stdClass $issues
     * @return string
     */
    private function format_import_issues(\stdClass $issues): string {
        $parts = [];
        if (!empty($issues->missing)) {
            $parts[] = get_string('learningpathimportmissingactivities', 'format_selfstudy',
                implode('; ', array_slice($issues->missing, 0, 20)));
        }
        if (!empty($issues->ambiguous)) {
            $parts[] = get_string('learningpathimportambiguousactivities', 'format_selfstudy',
                implode('; ', array_slice($issues->ambiguous, 0, 20)));
        }
        if (!empty($issues->invalid)) {
            $parts[] = get_string('learningpathimportinvalidactivities', 'format_selfstudy',
                implode('; ', array_slice($issues->invalid, 0, 20)));
        }

        return implode(' ', $parts);
    }

    /**
     * Formats an exported activity reference for diagnosis output.
     *
     * @param array $ref
     * @return string
     */
    private function format_activity_ref_label(array $ref): string {
        $modname = (string)($ref['modname'] ?? '?');
        $name = trim((string)($ref['name'] ?? ''));
        $label = $name !== '' ? '"' . $name . '"' : get_string('learningpathmissingactivity', 'format_selfstudy');
        $details = [$modname];
        if (!empty($ref['idnumber'])) {
            $details[] = 'idnumber=' . (string)$ref['idnumber'];
        }
        if (array_key_exists('sectionnum', $ref)) {
            $details[] = 'section=' . (int)$ref['sectionnum'];
        }

        return $label . ' [' . implode(', ', $details) . ']';
    }

    /**
     * Decodes configdata JSON.
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
     * Cleans an array of string identifiers.
     *
     * @param mixed $values
     * @return string[]
     */
    private function clean_string_array($values): array {
        return array_values(array_filter(array_map(static function($value): string {
            return clean_param((string)$value, PARAM_ALPHANUMEXT);
        }, is_array($values) ? $values : [])));
    }

    /**
     * Returns a non-conflicting import name.
     *
     * @param int $courseid
     * @param string $name
     * @return string
     */
    private function get_import_name(int $courseid, string $name): string {
        $name = trim($name) !== '' ? trim($name) : get_string('learningpathimportedname', 'format_selfstudy');
        $existing = array_map(static function(\stdClass $path): string {
            return (string)$path->name;
        }, $this->repository->get_paths($courseid));

        if (!in_array($name, $existing, true)) {
            return $name;
        }

        $base = $name . ' (' . get_string('learningpathimported', 'format_selfstudy') . ')';
        $candidate = $base;
        $counter = 2;
        while (in_array($candidate, $existing, true)) {
            $candidate = $base . ' ' . $counter;
            $counter++;
        }

        return $candidate;
    }
}
