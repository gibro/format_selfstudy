<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Derives competency profiles for selfstudy learning paths.
 *
 * Moodle core competencies are the only competency source. A path has no
 * competencies of its own: its profile is derived from the core competencies
 * linked to the path's station activities. When no station uses competencies,
 * every profile is empty and callers hide the competency layer entirely.
 */
class path_competency_service {

    /** @var path_repository */
    private $repository;

    /**
     * Constructor.
     *
     * @param path_repository|null $repository
     */
    public function __construct(?path_repository $repository = null) {
        $this->repository = $repository ?? new path_repository();
    }

    /**
     * Returns the derived competency profile of a path.
     *
     * Aggregates the course competencies linked to the path's stations. Each
     * entry carries the competency id, a display label and the number of
     * stations addressing it. Returns an empty array when core competencies
     * are disabled or no station uses one.
     *
     * @param int $courseid
     * @param int $pathid
     * @return \stdClass[]
     */
    public function profile_for_path(int $courseid, int $pathid): array {
        global $DB;

        $cmids = $this->get_station_cmids($pathid);
        if (!$cmids || !$this->competencies_enabled()) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cmid');
        $params['courseid'] = $courseid;

        $records = $DB->get_records_sql(
            "SELECT c.id, c.shortname, c.idnumber, COUNT(DISTINCT mc.cmid) AS stationcount
               FROM {competency_modulecomp} mc
               JOIN {competency_coursecomp} cc
                 ON cc.competencyid = mc.competencyid
                AND cc.courseid = :courseid
               JOIN {competency} c ON c.id = mc.competencyid
              WHERE mc.cmid $insql
           GROUP BY c.id, c.shortname, c.idnumber
           ORDER BY MIN(mc.sortorder) ASC, c.shortname ASC",
            $params
        );

        $profile = [];
        foreach ($records as $record) {
            $profile[] = (object)[
                'id' => (int)$record->id,
                'label' => $this->format_label($record),
                'stationcount' => (int)$record->stationcount,
            ];
        }

        return $profile;
    }

    /**
     * Returns the profile labels only, for compact display.
     *
     * @param int $courseid
     * @param int $pathid
     * @return string[]
     */
    public function profile_labels_for_path(int $courseid, int $pathid): array {
        return array_map(static function(\stdClass $entry): string {
            return $entry->label;
        }, $this->profile_for_path($courseid, $pathid));
    }

    /**
     * Returns the path stations addressing one competency.
     *
     * This is the groundwork for simple recommendations: given a competency a
     * learner wants to work on, these are the matching stations of the path.
     *
     * @param int $courseid
     * @param int $pathid
     * @param int $competencyid
     * @return \stdClass[] entries with cmid and name
     */
    public function stations_for_competency(int $courseid, int $pathid, int $competencyid): array {
        global $DB;

        $cmids = $this->get_station_cmids($pathid);
        if (!$cmids || !$this->competencies_enabled()) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cmid');
        $params['courseid'] = $courseid;
        $params['competencyid'] = $competencyid;

        $linkedcmids = $DB->get_fieldset_sql(
            "SELECT mc.cmid
               FROM {competency_modulecomp} mc
               JOIN {competency_coursecomp} cc
                 ON cc.competencyid = mc.competencyid
                AND cc.courseid = :courseid
              WHERE mc.competencyid = :competencyid
                AND mc.cmid $insql
           ORDER BY mc.sortorder ASC",
            $params
        );
        if (!$linkedcmids) {
            return [];
        }

        $modinfo = get_fast_modinfo($courseid);
        $stations = [];
        foreach ($linkedcmids as $cmid) {
            $cm = $modinfo->cms[(int)$cmid] ?? null;
            if (!$cm) {
                continue;
            }
            $stations[] = (object)[
                'cmid' => (int)$cm->id,
                'name' => format_string($cm->name),
            ];
        }

        return $stations;
    }

    /**
     * Returns the station course module ids of a path.
     *
     * @param int $pathid
     * @return int[]
     */
    private function get_station_cmids(int $pathid): array {
        $cmids = [];
        foreach ($this->repository->get_path_items($pathid) as $item) {
            if ($item->itemtype === path_repository::ITEM_STATION && !empty($item->cmid)) {
                $cmids[] = (int)$item->cmid;
            }
        }

        return array_values(array_unique($cmids));
    }

    /**
     * Returns whether Moodle core competencies are enabled.
     *
     * @return bool
     */
    private function competencies_enabled(): bool {
        try {
            return (bool)get_config('core_competency', 'enabled');
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /**
     * Formats one competency record into a display label.
     *
     * @param \stdClass $record
     * @return string
     */
    private function format_label(\stdClass $record): string {
        $shortname = format_string((string)$record->shortname, true);
        $idnumber = trim((string)$record->idnumber);

        return $idnumber !== '' ? $idnumber . ' - ' . $shortname : $shortname;
    }
}
