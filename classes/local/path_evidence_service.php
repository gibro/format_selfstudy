<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds a learner's evidence of achievement for a learning path.
 *
 * The evidence is always derived, never stored: it is computed on demand from
 * Moodle core activity completion, the path progress model and the derived
 * competency profile. Nothing is written, so the evidence can never go stale
 * against a changing course.
 */
class path_evidence_service {

    /** @var path_repository */
    private $repository;

    /** @var path_competency_service */
    private $competencyservice;

    /**
     * Constructor.
     *
     * @param path_repository|null $repository
     * @param path_competency_service|null $competencyservice
     */
    public function __construct(?path_repository $repository = null,
            ?path_competency_service $competencyservice = null) {
        $this->repository = $repository ?? new path_repository();
        $this->competencyservice = $competencyservice ?? new path_competency_service($this->repository);
    }

    /**
     * Builds the evidence object for one learner and path.
     *
     * @param \stdClass $course
     * @param int $pathid
     * @param int $userid
     * @return \stdClass
     */
    public function build_evidence(\stdClass $course, int $pathid, int $userid): \stdClass {
        $path = $pathid ? $this->repository->get_path($pathid) : null;

        $evidence = (object)[
            'pathid' => $pathid,
            'pathname' => $path ? format_string($path->name) : '',
            'hasdata' => false,
            'iscomplete' => false,
            'started' => false,
            'progress' => (object)['total' => 0, 'complete' => 0, 'percentage' => 0],
            'stations' => [],
            'milestones' => [],
            'competencies' => [],
            'competenciesachieved' => 0,
            'competenciestotal' => 0,
            'coursecomplete' => false,
        ];

        if (!$path) {
            return $evidence;
        }

        $progress = path_progress::calculate($course, $pathid, $userid);
        $outline = path_progress::outline($course, $pathid, $userid);

        $evidence->hasdata = true;
        $evidence->progress = (object)[
            'total' => (int)$progress->total,
            'complete' => (int)$progress->complete,
            'percentage' => (int)$progress->percentage,
        ];
        $evidence->iscomplete = $progress->total > 0 && $progress->complete >= $progress->total;
        $evidence->started = $progress->complete > 0;

        $seenmilestones = [];
        foreach ($outline as $entry) {
            if ($entry->type === path_repository::ITEM_STATION) {
                $evidence->stations[] = (object)[
                    'title' => $entry->title,
                    'status' => $entry->status,
                    'iscomplete' => $entry->status === 'complete',
                    'timemodified' => (int)($entry->timemodified ?? 0),
                    'competencies' => array_values((array)($entry->competencies ?? [])),
                ];
            } else if ($entry->type === path_repository::ITEM_MILESTONE) {
                // The grouped outline can emit the same milestone once per block;
                // collapse repeats so the evidence lists each milestone once.
                $key = $entry->title;
                if (isset($seenmilestones[$key])) {
                    if ($entry->status === 'complete') {
                        $seenmilestones[$key]->iscomplete = true;
                    }
                    continue;
                }
                $milestone = (object)[
                    'title' => $entry->title,
                    'iscomplete' => $entry->status === 'complete',
                ];
                $seenmilestones[$key] = $milestone;
                $evidence->milestones[] = $milestone;
            }
        }

        $evidence->competencies = $this->build_competency_evidence($course, $pathid, $userid);
        $evidence->competenciestotal = count($evidence->competencies);
        $evidence->competenciesachieved = count(array_filter($evidence->competencies,
            static function(\stdClass $entry): bool {
                return $entry->achieved;
            }));

        $evidence->coursecomplete = $this->is_course_complete($course, $userid);

        return $evidence;
    }

    /**
     * Returns whether a learner has completed a path in full.
     *
     * This is the canonical "path completed" signal. A certificate plugin can
     * consume it indirectly through Moodle course completion (see the plugin
     * documentation): the format does not issue certificates itself.
     *
     * @param \stdClass $course
     * @param int $pathid
     * @param int $userid
     * @return bool
     */
    public function is_path_complete(\stdClass $course, int $pathid, int $userid): bool {
        if (!$pathid) {
            return false;
        }

        $progress = path_progress::calculate($course, $pathid, $userid);
        return $progress->total > 0 && $progress->complete >= $progress->total;
    }

    /**
     * Derives per-competency achievement for the path.
     *
     * @param \stdClass $course
     * @param int $pathid
     * @param int $userid
     * @return \stdClass[]
     */
    private function build_competency_evidence(\stdClass $course, int $pathid, int $userid): array {
        $profile = $this->competencyservice->profile_for_path((int)$course->id, $pathid);
        if (!$profile) {
            return [];
        }

        $competencies = [];
        foreach ($profile as $entry) {
            $competencies[] = (object)[
                'id' => (int)$entry->id,
                'label' => $entry->label,
                'stationcount' => (int)$entry->stationcount,
                'achieved' => $this->is_competency_achieved((int)$course->id, $userid, (int)$entry->id),
            ];
        }

        return $competencies;
    }

    /**
     * Returns whether a learner is proficient in a course competency.
     *
     * @param int $courseid
     * @param int $userid
     * @param int $competencyid
     * @return bool
     */
    private function is_competency_achieved(int $courseid, int $userid, int $competencyid): bool {
        try {
            $usercompetency = \core_competency\api::get_user_competency_in_course($courseid, $userid, $competencyid);
            return $usercompetency ? (bool)$usercompetency->get('proficiency') : false;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /**
     * Returns whether the learner has completed the course.
     *
     * @param \stdClass $course
     * @param int $userid
     * @return bool
     */
    private function is_course_complete(\stdClass $course, int $userid): bool {
        try {
            $completion = new \completion_info($course);
            if (!$completion->is_enabled()) {
                return false;
            }
            return (bool)$completion->is_course_complete($userid);
        } catch (\Throwable $exception) {
            return false;
        }
    }
}
