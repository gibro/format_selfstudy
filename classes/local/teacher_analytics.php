<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\local;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/completionlib.php');

/**
 * Aggregated, account-free analytics for teachers.
 */
class teacher_analytics {

    /**
     * Builds anonymised aggregate analytics for a course.
     *
     * @param \stdClass $course
     * @param \core_courseformat\base $format
     * @return \stdClass
     */
    public static function get_course_report(\stdClass $course, \core_courseformat\base $format): \stdClass {
        $modinfo = get_fast_modinfo($course);
        $context = \context_course::instance($course->id);
        $enrolled = count_enrolled_users($context, '', 0, true);

        $sections = [];
        $activities = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            if (!$section->uservisible) {
                continue;
            }

            $options = $format->get_format_options($section);
            $sectionid = (int)$section->id;
            $sections[$sectionid] = (object)[
                'id' => $sectionid,
                'number' => (int)$section->section,
                'title' => get_section_name($course, $section),
                'pathkind' => ($options['pathkind'] ?? 'required') === 'optional' ? 'optional' : 'required',
                'started' => 0,
                'incomplete' => 0,
                'selected' => 0,
                'openrequired' => 0,
                'cmids' => [],
            ];

            foreach ($modinfo->sections[$section->section] ?? [] as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if (!activity_filter::is_learning_station($cm, true)) {
                    continue;
                }

                $activities[(int)$cm->id] = (object)[
                    'cmid' => (int)$cm->id,
                    'sectionid' => $sectionid,
                    'title' => format_string($cm->name, true),
                    'modname' => $cm->modname,
                    'started' => 0,
                    'complete' => 0,
                    'open' => 0,
                    'bottleneck' => 0,
                ];
                $sections[$sectionid]->cmids[] = (int)$cm->id;
            }
        }

        self::apply_completion_counts($course, $sections, $activities, $enrolled);
        self::apply_personal_path_choice_counts($course, $sections);

        return (object)[
            'enrolled' => $enrolled,
            'frequentstartedsections' => self::top_values($sections, 'started'),
            'dropoffsections' => self::top_values($sections, 'incomplete'),
            'selectedoptionalsections' => self::top_values(array_filter($sections,
                static function(\stdClass $section): bool {
                    return $section->pathkind === 'optional';
                }), 'selected'),
            'openrequiredstations' => self::top_values(array_filter($activities,
                static function(\stdClass $activity) use ($sections): bool {
                    return ($sections[$activity->sectionid]->pathkind ?? '') === 'required';
                }), 'open'),
            'bottlenecks' => self::top_values($activities, 'bottleneck'),
        ];
    }

    /**
     * Applies aggregate completion counts to sections and activities.
     *
     * @param \stdClass $course
     * @param \stdClass[] $sections
     * @param \stdClass[] $activities
     * @param int $enrolled
     */
    private static function apply_completion_counts(\stdClass $course, array &$sections, array &$activities,
            int $enrolled): void {
        global $DB;

        if (!$activities) {
            return;
        }

        foreach ($activities as $cmid => $activity) {
            $params = ['cmid' => $cmid];
            $activity->started = (int)$DB->count_records_select('course_modules_completion',
                'coursemoduleid = :cmid AND completionstate <> 0', $params);
            $activity->complete = (int)$DB->count_records_select('course_modules_completion',
                "coursemoduleid = :cmid AND completionstate IN (" . COMPLETION_COMPLETE . ', ' .
                    COMPLETION_COMPLETE_PASS . ')', $params);
            $activity->open = max(0, $enrolled - $activity->complete);
            $activity->bottleneck = max(0, $activity->started - $activity->complete);

            if (!isset($sections[$activity->sectionid])) {
                continue;
            }
            if ($sections[$activity->sectionid]->pathkind === 'required') {
                $sections[$activity->sectionid]->openrequired += $activity->open;
            }
        }

        foreach ($sections as $section) {
            if (empty($section->cmids)) {
                continue;
            }
            [$insql, $params] = $DB->get_in_or_equal($section->cmids, SQL_PARAMS_NAMED, 'cmid');
            $section->started = (int)$DB->count_records_sql(
                "SELECT COUNT(DISTINCT userid)
                   FROM {course_modules_completion}
                  WHERE coursemoduleid $insql
                    AND completionstate <> 0",
                $params
            );
            $section->incomplete = max(0, $section->started - (int)$DB->count_records_sql(
                "SELECT COUNT(DISTINCT userid)
                   FROM {course_modules_completion}
                  WHERE coursemoduleid $insql
                    AND completionstate IN (" . COMPLETION_COMPLETE . ', ' . COMPLETION_COMPLETE_PASS . ')',
                $params
            ));
        }
    }

    /**
     * Applies aggregate personal path section selection counts.
     *
     * @param \stdClass $course
     * @param \stdClass[] $sections
     */
    private static function apply_personal_path_choice_counts(\stdClass $course, array &$sections): void {
        global $DB;

        $sql = "SELECT i.sectionid, COUNT(DISTINCT p.userid) AS selectedcount
                  FROM {format_selfstudy_paths} p
                  JOIN {format_selfstudy_path_items} i ON i.pathid = p.id
                 WHERE p.courseid = :courseid
                   AND p.userid <> 0
                   AND i.sectionid <> 0
              GROUP BY i.sectionid";

        $records = $DB->get_records_sql($sql, ['courseid' => $course->id]);
        foreach ($records as $record) {
            $sectionid = (int)$record->sectionid;
            if (isset($sections[$sectionid])) {
                $sections[$sectionid]->selected = (int)$record->selectedcount;
            }
        }
    }

    /**
     * Returns rows sorted by one numeric property.
     *
     * @param array $records
     * @param string $field
     * @param int $limit
     * @return \stdClass[]
     */
    private static function top_values(array $records, string $field, int $limit = 5): array {
        $records = array_values(array_filter($records, static function(\stdClass $record) use ($field): bool {
            return (int)($record->{$field} ?? 0) > 0;
        }));
        usort($records, static function(\stdClass $left, \stdClass $right) use ($field): int {
            return ((int)$right->{$field} <=> (int)$left->{$field}) ?: strcmp($left->title, $right->title);
        });
        return array_slice($records, 0, $limit);
    }
}
