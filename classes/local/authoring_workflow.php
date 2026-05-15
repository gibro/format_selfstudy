<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\local;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/completionlib.php');

/**
 * Read-only status model for the teacher authoring workflow.
 */
class authoring_workflow {

    public const STATUS_NOTSTARTED = 'notstarted';
    public const STATUS_NEEDSWORK = 'needswork';
    public const STATUS_READY = 'ready';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_WARNING = 'warning';
    public const STATUS_BLOCKED = 'blocked';

    /** @var path_repository */
    private $pathrepository;

    /** @var path_snapshot_repository */
    private $snapshotrepository;

    /** @var experience_repository */
    private $experiencerepository;

    /** @var path_sync */
    private $sync;

    /**
     * Constructor.
     *
     * @param path_repository|null $pathrepository
     * @param path_snapshot_repository|null $snapshotrepository
     * @param experience_repository|null $experiencerepository
     * @param path_sync|null $sync
     */
    public function __construct(?path_repository $pathrepository = null,
            ?path_snapshot_repository $snapshotrepository = null,
            ?experience_repository $experiencerepository = null,
            ?path_sync $sync = null) {
        $this->pathrepository = $pathrepository ?? new path_repository();
        $this->snapshotrepository = $snapshotrepository ?? new path_snapshot_repository();
        $this->experiencerepository = $experiencerepository ?? new experience_repository();
        $this->sync = $sync ?? new path_sync($this->pathrepository);
    }

    /**
     * Builds the workflow state for one course and optional path.
     *
     * @param \stdClass $course
     * @param int $pathid
     * @return \stdClass
     */
    public function get_state(\stdClass $course, int $pathid = 0): \stdClass {
        $paths = $this->pathrepository->get_paths((int)$course->id);
        $path = $this->resolve_path($course, $paths, $pathid);
        $pathid = $path ? (int)$path->id : 0;
        $pathwithitems = $pathid ? $this->pathrepository->get_path_with_items($pathid) : null;
        $activities = $this->get_learning_activities($course);
        $sections = $this->get_visible_content_sections($course);
        $diagnosis = $pathid ? $this->safe_diagnose($course, $pathid) : null;
        $diagnosissummary = $diagnosis ? self::summarise_diagnosis($diagnosis) : $this->empty_diagnosis_summary();
        $activerevision = $pathid ? $this->snapshotrepository->get_active_revision($pathid) : null;
        $revisions = $pathid ? $this->snapshotrepository->get_revisions_for_path($pathid) : [];
        $experience = $this->summarise_experiences((int)$course->id);
        $metadatacheckenabled = $this->is_activity_metadata_enabled($course);
        $metadata = $metadatacheckenabled ? $this->summarise_activity_metadata($activities) : (object)[
            'total' => count($activities),
            'withoutmetadata' => 0,
        ];
        $missingcmids = $pathwithitems ? $this->get_missing_path_cmids($pathwithitems, $activities) : [];
        $draftchanged = $pathwithitems && $activerevision &&
            (int)($pathwithitems->timemodified ?? 0) > (int)($activerevision->timepublished ?? 0);

        $blockers = [];
        $warnings = [];
        if (!empty($diagnosissummary->blockers)) {
            $blockers = array_merge($blockers, $diagnosissummary->blockers);
        }
        if (!empty($diagnosissummary->warnings)) {
            $warnings = array_merge($warnings, $diagnosissummary->warnings);
        }
        if ($missingcmids) {
            $blockers[] = get_string('authoringworkflowblockermissingactivities', 'format_selfstudy',
                implode(', ', $missingcmids));
        }
        if ($draftchanged) {
            $warnings[] = get_string('authoringworkflowwarningdraftchanged', 'format_selfstudy');
        }
        if ($metadatacheckenabled && $metadata->withoutmetadata > 0) {
            $warnings[] = get_string('authoringworkflowwarningmetadata', 'format_selfstudy', $metadata->withoutmetadata);
        }
        if ($experience->missing > 0) {
            $warnings[] = get_string('authoringworkflowwarningmissingviews', 'format_selfstudy', $experience->missing);
        }

        $state = (object)[
            'courseid' => (int)$course->id,
            'pathid' => $pathid,
            'path' => $pathwithitems,
            'paths' => $paths,
            'activities' => $activities,
            'visiblecontentsections' => $sections,
            'diagnosis' => $diagnosis,
            'diagnosissummary' => $diagnosissummary,
            'activepublishedrevision' => $activerevision ? (int)$activerevision->revision : 0,
            'activerevision' => $activerevision,
            'revisions' => $revisions,
            'draftchanged' => $draftchanged,
            'experience' => $experience,
            'activitymetadata' => $metadata,
            'activitymetadatacheckenabled' => $metadatacheckenabled,
            'missingcmids' => $missingcmids,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'publishable' => $pathid > 0 && empty($blockers),
        ];
        $state->steps = $this->build_steps($course, $state);
        $state->nextaction = $this->get_next_action($state);

        return $state;
    }

    /**
     * Builds a compact grouped diagnosis summary.
     *
     * @param \stdClass $diagnosis
     * @return \stdClass
     */
    public static function summarise_diagnosis(\stdClass $diagnosis): \stdClass {
        $repairable = array_merge(
            $diagnosis->repairablecompletionmissing ?? [],
            $diagnosis->invalidcompletionavailability ?? [],
            $diagnosis->invalidstructureavailability ?? []
        );
        $blockers = [];
        foreach ($diagnosis->untranslatable ?? [] as $rule) {
            $reason = trim((string)($rule->reason ?? ''));
            $blockers[] = $reason !== '' ? $reason :
                get_string('learningpathsyncnottranslatable', 'format_selfstudy');
        }

        $warnings = [];
        if (!empty($diagnosis->repairablecompletionmissing)) {
            $warnings[] = get_string('authoringdiagnosiswarningcompletionrepairable', 'format_selfstudy',
                count($diagnosis->repairablecompletionmissing));
        }
        if (!empty($diagnosis->invalidcompletionavailability) || !empty($diagnosis->invalidstructureavailability)) {
            $warnings[] = get_string('authoringdiagnosiswarningavailabilityrepairable', 'format_selfstudy',
                count($diagnosis->invalidcompletionavailability) + count($diagnosis->invalidstructureavailability));
        }

        $status = self::STATUS_READY;
        if ($blockers) {
            $status = self::STATUS_BLOCKED;
        } else if ($repairable) {
            $status = self::STATUS_WARNING;
        }

        return (object)[
            'status' => $status,
            'blockercount' => count($blockers),
            'repairablecount' => count($repairable),
            'rulecount' => count($diagnosis->rules ?? []),
            'writablecount' => count(array_filter($diagnosis->rules ?? [], static function(\stdClass $rule): bool {
                return !empty($rule->translatable) && !empty($rule->availabilityjson);
            })),
            'completionmissingcount' => count($diagnosis->completionmissing ?? []),
            'warnings' => array_values(array_unique($warnings)),
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /**
     * Resolves the requested path or falls back to the first course-level path.
     *
     * @param \stdClass $course
     * @param \stdClass[] $paths
     * @param int $pathid
     * @return \stdClass|null
     */
    private function resolve_path(\stdClass $course, array $paths, int $pathid): ?\stdClass {
        foreach ($paths as $path) {
            if ($pathid > 0 && (int)$path->id === $pathid && (int)$path->courseid === (int)$course->id) {
                return $path;
            }
        }

        return $paths[0] ?? null;
    }

    /**
     * Returns visible, non-general course sections.
     *
     * @param \stdClass $course
     * @return \stdClass[]
     */
    private function get_visible_content_sections(\stdClass $course): array {
        $sections = [];
        foreach (get_fast_modinfo($course)->get_section_info_all() as $section) {
            if (empty($section->section) || empty($section->visible)) {
                continue;
            }
            $sections[] = $section;
        }

        return $sections;
    }

    /**
     * Returns usable learning activities.
     *
     * @param \stdClass $course
     * @return \cm_info[]
     */
    private function get_learning_activities(\stdClass $course): array {
        $activities = [];
        $modinfo = get_fast_modinfo($course);
        foreach ($modinfo->cms as $cm) {
            if (!empty($cm->deletioninprogress) || empty($cm->visible)) {
                continue;
            }
            if (activity_filter::is_learning_station($cm)) {
                $activities[(int)$cm->id] = $cm;
            }
        }

        return array_values($activities);
    }

    /**
     * Runs diagnosis without letting workflow pages fail on partial authoring data.
     *
     * @param \stdClass $course
     * @param int $pathid
     * @return \stdClass|null
     */
    private function safe_diagnose(\stdClass $course, int $pathid): ?\stdClass {
        try {
            return $this->sync->diagnose($course, $pathid);
        } catch (\Throwable $exception) {
            return (object)[
                'diagnosisexception' => $exception->getMessage(),
                'rules' => [],
                'completionmissing' => [],
                'repairablecompletionmissing' => [],
                'invalidcompletionavailability' => [],
                'invalidstructureavailability' => [],
                'untranslatable' => [(object)['reason' => $exception->getMessage()]],
            ];
        }
    }

    /**
     * Returns an empty diagnosis summary.
     *
     * @return \stdClass
     */
    private function empty_diagnosis_summary(): \stdClass {
        return (object)[
            'status' => self::STATUS_NOTSTARTED,
            'blockercount' => 0,
            'repairablecount' => 0,
            'rulecount' => 0,
            'writablecount' => 0,
            'completionmissingcount' => 0,
            'warnings' => [],
            'blockers' => [],
        ];
    }

    /**
     * Finds path cm references that no longer point to usable activities.
     *
     * @param \stdClass $path
     * @param \cm_info[] $activities
     * @return int[]
     */
    private function get_missing_path_cmids(\stdClass $path, array $activities): array {
        $activityids = [];
        foreach ($activities as $activity) {
            $activityids[(int)$activity->id] = true;
        }

        $missing = [];
        foreach ($path->items ?? [] as $item) {
            $cmid = (int)($item->cmid ?? 0);
            if ($cmid > 0 && empty($activityids[$cmid])) {
                $missing[$cmid] = $cmid;
            }
        }
        ksort($missing, SORT_NUMERIC);

        return array_values($missing);
    }

    /**
     * Counts activities with missing authoring metadata.
     *
     * @param \cm_info[] $activities
     * @return \stdClass
     */
    private function summarise_activity_metadata(array $activities): \stdClass {
        global $DB;

        $cmids = array_map(static function(\cm_info $cm): int {
            return (int)$cm->id;
        }, $activities);
        $records = [];
        if ($cmids) {
            [$insql, $params] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');
            $records = $DB->get_records_select('format_selfstudy_cmgoals', "cmid $insql", $params);
        }
        $bycmid = [];
        foreach ($records as $record) {
            $bycmid[(int)$record->cmid] = $record;
        }

        $withoutmetadata = 0;
        foreach ($activities as $activity) {
            $metadata = $bycmid[(int)$activity->id] ?? null;
            if (!$metadata || trim((string)$metadata->learninggoal) === '' ||
                    (int)$metadata->durationminutes <= 0 || trim((string)$metadata->competencies) === '') {
                $withoutmetadata++;
            }
        }

        return (object)[
            'total' => count($activities),
            'withoutmetadata' => $withoutmetadata,
        ];
    }

    /**
     * Returns whether optional activity metadata should be checked in the workflow.
     *
     * @param \stdClass $course
     * @return bool
     */
    private function is_activity_metadata_enabled(\stdClass $course): bool {
        try {
            $options = course_get_format($course)->get_format_options();
        } catch (\Throwable $exception) {
            return true;
        }

        return !array_key_exists('useactivitymetadata', $options) || !empty($options['useactivitymetadata']);
    }

    /**
     * Summarises stored learner view configuration without writing migration state.
     *
     * @param int $courseid
     * @return \stdClass
     */
    private function summarise_experiences(int $courseid): \stdClass {
        $records = $this->experiencerepository->get_course_experiences($courseid);
        $enabled = array_filter($records, static function(\stdClass $record): bool {
            return !empty($record->enabled) && empty($record->missing);
        });
        $missing = array_filter($records, static function(\stdClass $record): bool {
            return !empty($record->missing);
        });
        $enabledmissing = array_filter($records, static function(\stdClass $record): bool {
            return !empty($record->enabled) && !empty($record->missing);
        });

        return (object)[
            'total' => count($records),
            'enabled' => count($enabled),
            'missing' => count($missing),
            'enabledmissing' => count($enabledmissing),
            'basisavailable' => true,
        ];
    }

    /**
     * Builds workflow steps.
     *
     * @param \stdClass $course
     * @param \stdClass $state
     * @return \stdClass[]
     */
    private function build_steps(\stdClass $course, \stdClass $state): array {
        $pathid = (int)$state->pathid;
        $editorurl = new \moodle_url('/course/format/selfstudy/path_editor.php', ['id' => $course->id] +
            ($pathid ? ['pathid' => $pathid] : []));
        $diagnosisurl = new \moodle_url('/course/format/selfstudy/path_diagnosis.php', [
            'id' => $course->id,
            'pathid' => $pathid,
        ]);

        $steps = [];
        $steps[] = (object)[
            'key' => 'structure',
            'status' => count($state->visiblecontentsections) === 0 ? self::STATUS_NOTSTARTED :
                (count($state->activities) === 0 ? self::STATUS_NEEDSWORK : self::STATUS_READY),
            'title' => get_string('authoringworkflowstepstructure', 'format_selfstudy'),
            'summary' => get_string('authoringworkflowstepstructuresummary', 'format_selfstudy', (object)[
                'sections' => count($state->visiblecontentsections),
                'activities' => count($state->activities),
            ]),
            'actionurl' => new \moodle_url('/course/view.php', [
                'id' => $course->id,
                'section' => 1,
                'edit' => 1,
                'sesskey' => sesskey(),
            ]),
            'actionlabel' => get_string('authoringworkflowactionstructure', 'format_selfstudy'),
        ];

        $contentstatus = self::STATUS_READY;
        if (empty($state->activities)) {
            $contentstatus = self::STATUS_NEEDSWORK;
        } else if (!empty($state->diagnosissummary->repairablecount) ||
                (!empty($state->activitymetadatacheckenabled) && $state->activitymetadata->withoutmetadata > 0)) {
            $contentstatus = self::STATUS_WARNING;
        }
        $steps[] = (object)[
            'key' => 'content',
            'status' => $contentstatus,
            'title' => get_string('authoringworkflowstepcontent', 'format_selfstudy'),
            'summary' => get_string(!empty($state->activitymetadatacheckenabled) ?
                'authoringworkflowstepcontentsummary' : 'authoringworkflowstepcontentsummarydisabled',
                'format_selfstudy', (object)[
                    'activities' => count($state->activities),
                    'metadata' => $state->activitymetadata->withoutmetadata,
                ]),
            'actionurl' => new \moodle_url('/course/format/selfstudy/path_editor.php', ['id' => $course->id] +
                ($pathid ? ['pathid' => $pathid] : []) + ['showactivitysettings' => 1],
                'format-selfstudy-activitysettings'),
            'actionlabel' => get_string('authoringworkflowactioncontent', 'format_selfstudy'),
        ];

        $pathstatus = self::STATUS_NOTSTARTED;
        if ($state->path) {
            $pathstatus = empty($state->path->items) || $state->missingcmids ? self::STATUS_NEEDSWORK : self::STATUS_READY;
        }
        $steps[] = (object)[
            'key' => 'path',
            'status' => $pathstatus,
            'title' => get_string('authoringworkflowsteppath', 'format_selfstudy'),
            'summary' => $state->path ?
                get_string('authoringworkflowsteppathsummary', 'format_selfstudy', count($state->path->items ?? [])) :
                get_string('authoringworkflowsteppathsummarynone', 'format_selfstudy'),
            'actionurl' => $editorurl,
            'actionlabel' => get_string('authoringworkflowactionpath', 'format_selfstudy'),
        ];

        $steps[] = (object)[
            'key' => 'check',
            'status' => $state->diagnosissummary->status,
            'title' => get_string('authoringworkflowstepcheck', 'format_selfstudy'),
            'summary' => get_string('authoringworkflowstepchecksummary', 'format_selfstudy', (object)[
                'blockers' => $state->diagnosissummary->blockercount,
                'repairable' => $state->diagnosissummary->repairablecount,
            ]),
            'actionurl' => $pathid ? $diagnosisurl : $editorurl,
            'actionlabel' => get_string('authoringworkflowactioncheck', 'format_selfstudy'),
        ];

        $publishstatus = $state->activerevision ? self::STATUS_PUBLISHED : self::STATUS_NOTSTARTED;
        if ($state->draftchanged) {
            $publishstatus = self::STATUS_WARNING;
        }
        $steps[] = (object)[
            'key' => 'publish',
            'status' => $publishstatus,
            'title' => get_string('authoringworkflowsteppublish', 'format_selfstudy'),
            'summary' => $state->activerevision ?
                get_string('authoringworkflowsteppublishsummaryrevision', 'format_selfstudy', (object)[
                    'revision' => (int)$state->activerevision->revision,
                    'timepublished' => userdate((int)$state->activerevision->timepublished),
                ]) :
                get_string('authoringworkflowsteppublishsummarynone', 'format_selfstudy'),
            'actionurl' => $pathid ? $editorurl : null,
            'actionlabel' => get_string('authoringworkflowactionpublish', 'format_selfstudy'),
        ];

        $viewstatus = self::STATUS_READY;
        if ($state->experience->enabledmissing > 0) {
            $viewstatus = self::STATUS_NEEDSWORK;
        } else if ($state->experience->missing > 0) {
            $viewstatus = self::STATUS_WARNING;
        }
        $steps[] = (object)[
            'key' => 'views',
            'status' => $viewstatus,
            'title' => get_string('authoringworkflowstepviews', 'format_selfstudy'),
            'summary' => get_string('authoringworkflowstepviewssummary', 'format_selfstudy', (object)[
                'enabled' => $state->experience->enabled,
                'missing' => $state->experience->missing,
            ]),
            'actionurl' => new \moodle_url('/course/format/selfstudy/experience_settings.php', ['id' => $course->id]),
            'actionlabel' => get_string('authoringworkflowactionviews', 'format_selfstudy'),
        ];

        return $steps;
    }

    /**
     * Returns the next recommended workflow action.
     *
     * @param \stdClass $state
     * @return \stdClass|null
     */
    private function get_next_action(\stdClass $state): ?\stdClass {
        foreach ($state->steps as $step) {
            if (in_array($step->status, [self::STATUS_NOTSTARTED, self::STATUS_NEEDSWORK, self::STATUS_BLOCKED,
                    self::STATUS_WARNING], true)) {
                return $step;
            }
        }

        return $state->steps[5] ?? null;
    }
}
