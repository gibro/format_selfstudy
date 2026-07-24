<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Small HTML renderer for the teacher authoring workflow.
 */
class authoring_renderer {

    /**
     * Renders the workflow step list.
     *
     * @param \stdClass $state
     * @return string
     */
    public function workflow(\stdClass $state): string {
        $items = '';
        $number = 0;
        foreach ($state->steps as $step) {
            // Nothing to do here once the active revision matches the draft, so the
            // step stays out of the way instead of adding a card without a task.
            if ($step->key === 'publish' && $step->status === authoring_workflow::STATUS_PUBLISHED) {
                continue;
            }

            $statuslabel = get_string('authoringworkflowstatus' . $step->status, 'format_selfstudy');
            $action = '';
            if ($step->key === 'publish') {
                $action = $this->publish_form($state);
            } else if (!empty($step->actionurl)) {
                $action = \html_writer::link($step->actionurl, $step->actionlabel,
                    ['class' => 'btn btn-secondary btn-sm']);
            }
            $index = $number++;
            $items .= \html_writer::tag('li',
                \html_writer::div((string)($index + 1), 'format-selfstudy-authoring-stepnumber') .
                \html_writer::div(
                    \html_writer::div(
                        \html_writer::tag('h3', \s($step->title)) .
                        \html_writer::span(\s($statuslabel),
                            'format-selfstudy-authoring-status format-selfstudy-authoring-status-' . $step->status),
                        'format-selfstudy-authoring-steptitle'
                    ) .
                    \html_writer::div(\s($step->summary), 'format-selfstudy-authoring-stepsummary') .
                    $action,
                    'format-selfstudy-authoring-stepbody'
                ),
                ['class' => 'format-selfstudy-authoring-step format-selfstudy-authoring-step-' . $step->status]
            );
        }

        return \html_writer::div(
            \html_writer::tag('h2', get_string('authoringworkflowtitle', 'format_selfstudy'),
                ['class' => 'format-selfstudy-authoring-workflowtitle']) .
            \html_writer::tag('p', get_string('authoringworkflowintro', 'format_selfstudy'),
                ['class' => 'format-selfstudy-authoring-workflowintro']) .
            \html_writer::tag('ol', $items, [
                'class' => 'format-selfstudy-authoring-workflow',
                'aria-label' => get_string('authoringworkflowtitle', 'format_selfstudy'),
            ]),
            'format-selfstudy-authoring-workflowwrap'
        );
    }

    /**
     * Renders the publish control inside the publish step.
     *
     * Publishing happens right here instead of only in the editor. When the course
     * has more than one learning path, the teacher picks which one goes live.
     *
     * @param \stdClass $state
     * @return string
     */
    private function publish_form(\stdClass $state): string {
        $paths = array_values(array_filter((array)($state->paths ?? []), static function($path): bool {
            return (int)($path->userid ?? 0) === 0;
        }));
        if (!$paths) {
            return '';
        }

        $select = '';
        if (count($paths) > 1) {
            $options = [];
            foreach ($paths as $path) {
                $options[(int)$path->id] = format_string($path->name);
            }
            $select = \html_writer::div(
                \html_writer::label(get_string('authoringworkflowpublishpath', 'format_selfstudy'),
                    'format-selfstudy-authoring-publishpath') .
                \html_writer::select($options, 'pathid', (int)$state->pathid, false,
                    ['id' => 'format-selfstudy-authoring-publishpath', 'class' => 'custom-select']),
                'format-selfstudy-authoring-publishpick'
            );
        } else {
            $select = \html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'pathid',
                'value' => (int)($paths[0]->id ?? 0),
            ]);
        }

        return \html_writer::tag('form',
            \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
            \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => (int)$state->courseid]) .
            \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'selfstudypublish', 'value' => 1]) .
            $select .
            \html_writer::tag('button', get_string('authoringworkflowpublishnow', 'format_selfstudy'), [
                'type' => 'submit',
                'class' => 'btn btn-primary btn-sm',
            ]),
            [
                'method' => 'post',
                'action' => (new \moodle_url('/course/view.php', ['id' => (int)$state->courseid]))->out(false),
                'class' => 'format-selfstudy-authoring-publishform',
            ]
        );
    }

    /**
     * Renders a compact publication status panel.
     *
     * @param \stdClass $state
     * @return string
     */
    public function publish_status(\stdClass $state): string {
        if (empty($state->path)) {
            return \html_writer::div(get_string('authoringpublishstatusnopath', 'format_selfstudy'),
                'format-selfstudy-authoring-publishstatus');
        }

        if (!empty($state->activerevision)) {
            $revision = $state->activerevision;
            $text = get_string('authoringpublishstatusrevision', 'format_selfstudy', (object)[
                'revision' => (int)$revision->revision,
                'timepublished' => userdate((int)$revision->timepublished),
            ]);
            if (!empty($state->draftchanged)) {
                $text .= ' ' . get_string('authoringworkflowwarningdraftchanged', 'format_selfstudy');
            }
        } else {
            $text = get_string('authoringpublishstatusunpublished', 'format_selfstudy');
        }

        return \html_writer::div(\s($text), 'format-selfstudy-authoring-publishstatus');
    }

    /**
     * Renders the derived competency profile of the current path.
     *
     * The profile is derived from the competencies linked to the path's
     * stations. Renders nothing when no station uses competencies, so the
     * competency layer stays invisible in courses that do not work with it.
     *
     * @param \stdClass $state
     * @return string
     */
    public function competency_summary(\stdClass $state): string {
        $profile = array_values((array)($state->competencyprofile ?? []));
        if (!$profile) {
            return '';
        }

        $items = array_map(static function(\stdClass $entry): string {
            $count = (int)$entry->stationcount;
            $countlabel = $count === 1 ?
                get_string('authoringcompetencystation', 'format_selfstudy') :
                get_string('authoringcompetencystations', 'format_selfstudy', $count);
            return \s($entry->label . ' (' . $countlabel . ')');
        }, $profile);

        return \html_writer::div(
            \html_writer::tag('h3', get_string('authoringcompetencysummary', 'format_selfstudy')) .
            \html_writer::alist($items),
            'format-selfstudy-authoring-competencies'
        );
    }

    /**
     * Renders blockers and warnings.
     *
     * @param \stdClass $state
     * @return string
     */
    public function issues(\stdClass $state): string {
        $output = '';
        if (!empty($state->blockers)) {
            $output .= \html_writer::div(
                \html_writer::tag('h3', get_string('authoringworkflowblockers', 'format_selfstudy')) .
                \html_writer::alist(array_map('\s', $state->blockers)),
                'format-selfstudy-authoring-issues format-selfstudy-authoring-issues-blocked'
            );
        }
        if (!empty($state->warnings)) {
            $output .= \html_writer::div(
                \html_writer::tag('h3', get_string('authoringworkflowwarnings', 'format_selfstudy')) .
                \html_writer::alist(array_map('\s', $state->warnings)),
                'format-selfstudy-authoring-issues format-selfstudy-authoring-issues-warning'
            );
        }

        return $output;
    }

    /**
     * Renders revision history.
     *
     * @param \stdClass $state
     * @return string
     */
    public function revisions(\stdClass $state): string {
        $rows = '';
        foreach ($state->revisions ?? [] as $revision) {
            $rows .= \html_writer::tag('tr',
                \html_writer::tag('td', \s('#' . (int)$revision->revision)) .
                \html_writer::tag('td', \s(userdate((int)$revision->timepublished))) .
                \html_writer::tag('td', \s($this->user_name((int)$revision->publishedby))) .
                \html_writer::tag('td', \s(get_string('authoringrevisionstatus' . $revision->status,
                    'format_selfstudy'))) .
                \html_writer::tag('td', \s((int)$revision->schema))
            );
        }
        if ($rows === '') {
            $rows = \html_writer::tag('tr',
                \html_writer::tag('td', get_string('authoringrevisionsnone', 'format_selfstudy'), ['colspan' => 5]));
        }

        return \html_writer::div(
            \html_writer::tag('h3', get_string('authoringrevisions', 'format_selfstudy')) .
            \html_writer::tag('table',
                \html_writer::tag('thead', \html_writer::tag('tr',
                    \html_writer::tag('th', get_string('authoringrevision', 'format_selfstudy')) .
                    \html_writer::tag('th', get_string('authoringrevisiontime', 'format_selfstudy')) .
                    \html_writer::tag('th', get_string('authoringrevisionuser', 'format_selfstudy')) .
                    \html_writer::tag('th', get_string('authoringrevisionstatus', 'format_selfstudy')) .
                    \html_writer::tag('th', get_string('authoringrevisionschema', 'format_selfstudy')))) .
                \html_writer::tag('tbody', $rows),
                ['class' => 'generaltable format-selfstudy-authoring-revisiontable']) .
            \html_writer::div(get_string('authoringrevisionsrollbacklater', 'format_selfstudy'), 'text-muted small'),
            'format-selfstudy-authoring-revisions'
        );
    }

    /**
     * Renders the diagnosis assistant summary.
     *
     * @param \stdClass $summary
     * @return string
     */
    public function diagnosis_summary(\stdClass $summary): string {
        $statuslabel = get_string('authoringworkflowstatus' . $summary->status, 'format_selfstudy');
        $next = $summary->status === authoring_workflow::STATUS_BLOCKED ?
            get_string('authoringdiagnosisnexteditor', 'format_selfstudy') :
            ($summary->status === authoring_workflow::STATUS_WARNING ?
                get_string('authoringdiagnosisnextrepair', 'format_selfstudy') :
                get_string('authoringdiagnosisnextpublish', 'format_selfstudy'));

        $cards = '';
        foreach ([
            get_string('authoringdiagnosisblockers', 'format_selfstudy') => $summary->blockercount,
            get_string('authoringdiagnosisrepairable', 'format_selfstudy') => $summary->repairablecount,
            get_string('authoringdiagnosisrules', 'format_selfstudy') => $summary->writablecount,
        ] as $label => $value) {
            $cards .= \html_writer::div(
                \html_writer::div(\s((string)$value), 'format-selfstudy-authoring-metricvalue') .
                \html_writer::div(\s($label), 'format-selfstudy-authoring-metriclabel'),
                'format-selfstudy-authoring-metric'
            );
        }

        $details = '';
        if (!empty($summary->blockers)) {
            $details .= \html_writer::div(
                \html_writer::tag('h3', get_string('authoringworkflowblockers', 'format_selfstudy')) .
                \html_writer::alist(array_map('\s', $summary->blockers)),
                'format-selfstudy-authoring-issues format-selfstudy-authoring-issues-blocked'
            );
        }
        if (!empty($summary->warnings)) {
            $details .= \html_writer::div(
                \html_writer::tag('h3', get_string('authoringworkflowwarnings', 'format_selfstudy')) .
                \html_writer::alist(array_map('\s', $summary->warnings)),
                'format-selfstudy-authoring-issues format-selfstudy-authoring-issues-warning'
            );
        }

        return \html_writer::div(
            \html_writer::tag('h3', get_string('authoringdiagnosissummary', 'format_selfstudy')) .
            \html_writer::div(\s($statuslabel), 'format-selfstudy-authoring-diagnosisstatus ' .
                'format-selfstudy-authoring-status-' . $summary->status) .
            \html_writer::div(\s($next), 'format-selfstudy-authoring-next') .
            \html_writer::div($cards, 'format-selfstudy-authoring-metrics') .
            $details,
            'format-selfstudy-authoring-diagnosis'
        );
    }

    /**
     * Resolves a user display name for revision history.
     *
     * @param int $userid
     * @return string
     */
    private function user_name(int $userid): string {
        global $DB;

        if ($userid <= 0) {
            return get_string('unknownuser');
        }
        $user = $DB->get_record('user', ['id' => $userid], '*', IGNORE_MISSING);

        return $user ? fullname($user) : get_string('unknownuser');
    }
}
