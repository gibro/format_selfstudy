<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Coordinates publishing a learning path into Moodle core availability.
 */
class path_publish_service {

    /** @var path_repository */
    private $repository;

    /** @var path_sync */
    private $sync;

    /** @var path_snapshot_builder */
    private $snapshotbuilder;

    /** @var path_snapshot_repository */
    private $snapshotrepository;

    /**
     * Constructor.
     *
     * @param path_repository|null $repository
     * @param path_sync|null $sync
     * @param path_snapshot_builder|null $snapshotbuilder
     * @param path_snapshot_repository|null $snapshotrepository
     */
    public function __construct(?path_repository $repository = null, ?path_sync $sync = null,
            ?path_snapshot_builder $snapshotbuilder = null, ?path_snapshot_repository $snapshotrepository = null) {
        $this->repository = $repository ?? new path_repository();
        $this->sync = $sync ?? new path_sync($this->repository);
        $this->snapshotbuilder = $snapshotbuilder ?? new path_snapshot_builder();
        $this->snapshotrepository = $snapshotrepository ?? new path_snapshot_repository();
    }

    /**
     * Publishes a course-level path after the editor has saved its draft items.
     *
     * @param \stdClass $course
     * @param int $pathid
     * @return \stdClass
     */
    public function publish_course_path(\stdClass $course, int $pathid): \stdClass {
        $result = (object)[
            'published' => false,
            'written' => 0,
            'skipped' => 0,
            'errors' => [],
            'fixed' => 0,
            'snapshot' => false,
            'revision' => 0,
            'publishedby' => 0,
            'timepublished' => 0,
            'diagnosisstatus' => '',
            'blockers' => [],
            'warnings' => [],
            'nextactions' => [],
        ];

        $diagnosis = $this->sync->diagnose($course, $pathid);
        $this->apply_diagnosis_summary($result, $diagnosis);
        if (!empty($diagnosis->invalidcompletionavailability) || !empty($diagnosis->invalidstructureavailability)) {
            $cleanup = $this->sync->cleanup_invalid_availability($course, $pathid);
            $result->fixed += (int)($cleanup->fixed ?? 0);
            if (!empty($cleanup->errors)) {
                $result->errors = array_values($cleanup->errors);
                $result->warnings = array_values(array_unique(array_merge($result->warnings, $result->errors)));
                $result->nextactions[] = get_string('authoringdiagnosisnextrepair', 'format_selfstudy');
                return $result;
            }
            if (!empty($cleanup->fixed)) {
                $diagnosis = $this->sync->diagnose($course, $pathid);
                $this->apply_diagnosis_summary($result, $diagnosis);
            }
        }

        if (!empty($diagnosis->untranslatable)) {
            $errors = array_map(static function(\stdClass $rule): string {
                return trim((string)($rule->reason ?? '')) !== '' ?
                    (string)$rule->reason :
                    get_string('learningpathsyncnottranslatable', 'format_selfstudy');
            }, $diagnosis->untranslatable);
            $result->errors = array_values(array_unique($errors));
            $result->blockers = $result->errors;
            $result->nextactions[] = get_string('authoringdiagnosisnexteditor', 'format_selfstudy');
            return $result;
        }

        $path = $this->repository->get_path_with_items($pathid);
        if (!$path || (int)$path->courseid !== (int)$course->id || (int)($path->userid ?? 0) !== 0) {
            $result->errors = [get_string('invalidrecord', 'error')];
            return $result;
        }
        $snapshot = $this->snapshotbuilder->build_snapshot($course, $path);

        $syncresult = $this->sync->sync($course, $pathid);
        $result->written = (int)($syncresult->written ?? 0);
        $result->skipped = (int)($syncresult->skipped ?? 0);
        if (!empty($syncresult->errors)) {
            $result->errors = array_values($syncresult->errors);
            $result->warnings = array_values(array_unique(array_merge($result->warnings, $result->errors)));
            $result->nextactions[] = get_string('authoringdiagnosisnextrepair', 'format_selfstudy');
            return $result;
        }

        $publishedby = (int)($GLOBALS['USER']->id ?? 0);
        $timepublished = time();
        $revision = $this->snapshotrepository->save_revision($pathid, (int)$course->id, 0, $snapshot, $publishedby,
            $timepublished);
        $this->repository->publish_course_path((int)$course->id, $pathid);
        $result->snapshot = true;
        $result->published = true;
        $result->revision = (int)$revision->revision;
        $result->publishedby = $publishedby;
        $result->timepublished = $timepublished;

        return $result;
    }

    /**
     * Adds optional UI-oriented diagnosis fields to a publish result.
     *
     * @param \stdClass $result
     * @param \stdClass $diagnosis
     */
    private function apply_diagnosis_summary(\stdClass $result, \stdClass $diagnosis): void {
        $summary = authoring_workflow::summarise_diagnosis($diagnosis);
        $result->diagnosisstatus = $summary->status;
        $result->blockers = $summary->blockers;
        $result->warnings = $summary->warnings;
        if ($summary->status === authoring_workflow::STATUS_BLOCKED) {
            $result->nextactions = [get_string('authoringdiagnosisnexteditor', 'format_selfstudy')];
        } else if ($summary->status === authoring_workflow::STATUS_WARNING) {
            $result->nextactions = [get_string('authoringdiagnosisnextrepair', 'format_selfstudy')];
        } else {
            $result->nextactions = [get_string('authoringdiagnosisnextpublish', 'format_selfstudy')];
        }
    }
}
