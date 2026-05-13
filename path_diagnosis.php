<?php
// This file is part of Moodle - http://moodle.org/

$configpaths = [__DIR__ . '/../../../config.php'];
if (!empty($_SERVER['DOCUMENT_ROOT'])) {
    $configpaths[] = rtrim($_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR) . '/config.php';
}

foreach ($configpaths as $configpath) {
    if (is_readable($configpath)) {
        require_once($configpath);
        break;
    }
}

if (empty($CFG)) {
    throw new RuntimeException('Unable to locate Moodle config.php.');
}
require_once($CFG->libdir . '/completionlib.php');

use format_selfstudy\local\path_repository;
use format_selfstudy\local\path_sync;

$courseid = required_param('id', PARAM_INT);
$pathid = required_param('pathid', PARAM_INT);

$course = get_course($courseid);
require_login($course);

$coursecontext = context_course::instance($course->id);
require_capability('moodle/course:update', $coursecontext);

$format = course_get_format($course);
if ($format->get_format() !== 'selfstudy') {
    throw new moodle_exception('invalidcourseformat', 'error');
}

$repository = new path_repository();
$path = $repository->get_path($pathid);
if (!$path || (int)$path->courseid !== (int)$course->id) {
    throw new moodle_exception('invalidrecord', 'error');
}

$baseurl = new moodle_url('/course/format/selfstudy/path_diagnosis.php', [
    'id' => $course->id,
    'pathid' => $pathid,
]);

$PAGE->set_url($baseurl);
$PAGE->set_context($coursecontext);
$PAGE->set_course($course);
$PAGE->set_pagelayout('course');
$PAGE->set_title(get_string('learningpathsyncpreview', 'format_selfstudy'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_secondary_active_tab('coursereuse');
$PAGE->requires->js_call_amd('format_selfstudy/patheditor', 'init', [
    [
        'cleanupConfirm' => get_string('learningpathsynccleanupinvalidconfirm', 'format_selfstudy'),
        'syncConfirm' => get_string('learningpathsyncconfirm', 'format_selfstudy'),
    ],
]);

$sync = new path_sync($repository);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $action = optional_param('action', 'sync', PARAM_ALPHAEXT);
    if ($action === 'repairall' || $action === 'cleanupinvalid') {
        $result = $action === 'repairall' ?
            $sync->repair_path_issues($course, $pathid) :
            $sync->cleanup_invalid_availability($course, $pathid);
        $message = $action === 'repairall' ?
            get_string('learningpathsyncrepairdone', 'format_selfstudy', (object)[
                'fixed' => $result->fixed,
                'completionfixed' => $result->completionfixed ?? 0,
                'availabilityfixed' => $result->availabilityfixed ?? $result->fixed,
                'skipped' => $result->skipped,
            ]) :
            get_string('learningpathsynccleanupinvaliddone', 'format_selfstudy', (object)[
                'fixed' => $result->fixed,
                'skipped' => $result->skipped,
            ]);
        if (!empty($result->errors)) {
            $message .= html_writer::alist(array_map('s', $result->errors));
        }
        redirect($baseurl, $message, null,
            $result->fixed > 0 ? \core\output\notification::NOTIFY_SUCCESS :
                \core\output\notification::NOTIFY_INFO);
    } else {
        $repair = $sync->repair_path_issues($course, $pathid);
        $result = $sync->sync($course, $pathid);
        $message = get_string('learningpathsyncdone', 'format_selfstudy', (object)[
            'written' => $result->written,
            'skipped' => $result->skipped,
        ]);
        if (!empty($repair->fixed)) {
            $message .= html_writer::div(get_string('learningpathsyncrepairdone', 'format_selfstudy', (object)[
                'fixed' => $repair->fixed,
                'completionfixed' => $repair->completionfixed,
                'availabilityfixed' => $repair->availabilityfixed,
                'skipped' => $repair->skipped,
            ]));
        }
        if (!empty($result->errors)) {
            $message .= html_writer::alist(array_map('s', $result->errors));
        }
        redirect($baseurl, $message, null,
            $result->written > 0 ? \core\output\notification::NOTIFY_SUCCESS :
                \core\output\notification::NOTIFY_WARNING);
    }
}

$diagnosis = $sync->diagnose($course, $pathid);

echo $OUTPUT->header();
echo html_writer::start_div('format-selfstudy-patheditor format-selfstudy-pathdiagnosis');
echo $OUTPUT->heading(get_string('learningpathsyncpreview', 'format_selfstudy'), 2);
echo html_writer::tag('p', get_string('learningpathsyncpreviewintro', 'format_selfstudy'), ['class' => 'text-muted']);
echo html_writer::start_div('format-selfstudy-patheditor-toolbar');
echo html_writer::link(new moodle_url('/course/format/selfstudy/path_editor.php', [
    'id' => $course->id,
    'pathid' => $pathid,
]), get_string('learningpatheditor', 'format_selfstudy'), ['class' => 'btn btn-secondary']);
echo html_writer::link(new moodle_url('/course/view.php', ['id' => $course->id]),
    get_string('viewcourse', 'format_selfstudy'), ['class' => 'btn btn-secondary']);
echo html_writer::end_div();

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $baseurl->out(false),
    'class' => 'format-selfstudy-pathdiagnosis-actions',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::tag('button', get_string('learningpathsyncbutton', 'format_selfstudy'), [
    'type' => 'submit',
    'class' => 'btn btn-primary',
    'name' => 'action',
    'value' => 'sync',
    'data-format-selfstudy-sync-submit' => '1',
]);
if (!empty($diagnosis->repairablecompletionmissing) || !empty($diagnosis->invalidcompletionavailability) ||
        !empty($diagnosis->invalidstructureavailability)) {
    echo html_writer::tag('button', get_string('learningpathsyncrepairbutton', 'format_selfstudy'), [
        'type' => 'submit',
        'class' => 'btn btn-secondary',
        'name' => 'action',
        'value' => 'repairall',
        'data-format-selfstudy-cleanup-invalid-submit' => '1',
    ]);
    echo html_writer::tag('button', get_string('learningpathsynccleanupinvalidbutton', 'format_selfstudy'), [
        'type' => 'submit',
        'class' => 'btn btn-secondary',
        'name' => 'action',
        'value' => 'cleanupinvalid',
        'data-format-selfstudy-cleanup-invalid-submit' => '1',
    ]);
}
echo html_writer::end_tag('form');

$summaryitems = [
    get_string('learningpathsynccompletionenabled', 'format_selfstudy') . ': ' . count($diagnosis->completionenabled),
    get_string('learningpathsynccompletionmissing', 'format_selfstudy') . ': ' . count($diagnosis->completionmissing),
    get_string('learningpathsyncrepairablecompletionmissing', 'format_selfstudy') . ': ' .
        count($diagnosis->repairablecompletionmissing),
    get_string('learningpathsyncpassinggrade', 'format_selfstudy') . ': ' . count($diagnosis->passinggrade),
    get_string('learningpathsyncexistingavailability', 'format_selfstudy') . ': ' . count($diagnosis->existingavailability),
    get_string('learningpathsynccleanupinvalidsummary', 'format_selfstudy') . ': ' .
        count($diagnosis->invalidcompletionavailability),
    get_string('learningpathsyncinvalidstructuresummary', 'format_selfstudy') . ': ' .
        count($diagnosis->invalidstructureavailability),
    get_string('learningpathsyncuntranslatable', 'format_selfstudy') . ': ' . count($diagnosis->untranslatable),
];
echo html_writer::alist($summaryitems, ['class' => 'format-selfstudy-pathdiagnosis-summary']);

echo html_writer::start_div('format-selfstudy-pathdiagnosis-grid');
echo format_selfstudy_path_diagnosis_render_activities($diagnosis);
echo format_selfstudy_path_diagnosis_render_rules($diagnosis);
echo html_writer::end_div();
echo html_writer::end_div();
echo $OUTPUT->footer();

/**
 * Renders activity facts for the diagnosis.
 *
 * @param stdClass $diagnosis
 * @return string
 */
function format_selfstudy_path_diagnosis_render_activities(stdClass $diagnosis): string {
    $rows = '';
    $repairablecompletion = [];
    foreach ($diagnosis->repairablecompletionmissing ?? [] as $activity) {
        $repairablecompletion[(int)$activity->cmid] = true;
    }
    foreach ($diagnosis->activities as $activity) {
        $badges = [];
        $badges[] = format_selfstudy_path_diagnosis_badge(
            $activity->completionenabled ? get_string('learningpathsynchascompletion', 'format_selfstudy') :
                get_string('learningpathsyncnocompletion', 'format_selfstudy'),
            $activity->completionenabled ? 'success' : 'warning'
        );
        if ($activity->supportsgradepass) {
            $label = $activity->hasgradepass ?
                get_string('learningpathsyncgradepassvalue', 'format_selfstudy', $activity->gradepass) :
                get_string('learningpathsyncgradepasssupported', 'format_selfstudy');
            $badges[] = format_selfstudy_path_diagnosis_badge($label, 'info');
        }
        if (!empty($activity->availabilitysummary)) {
            $badges[] = format_selfstudy_path_diagnosis_badge($activity->availabilitysummary, 'neutral');
        }
        if (!empty($repairablecompletion[(int)$activity->cmid])) {
            $badges[] = format_selfstudy_path_diagnosis_badge(
                get_string('learningpathsyncrepairablecompletion', 'format_selfstudy'), 'info');
        }
        if (!empty($activity->invalidcompletionavailability) || !empty($activity->invalidstructureavailability)) {
            $badges[] = format_selfstudy_path_diagnosis_badge(
                get_string('learningpathsyncrepairableavailability', 'format_selfstudy'), 'warning');
        }

        $rows .= html_writer::tag('tr',
            html_writer::tag('td', s($activity->name) . html_writer::div(s($activity->modname), 'text-muted small')) .
            html_writer::tag('td', implode(' ', $badges))
        );
    }

    if ($rows === '') {
        $rows = html_writer::tag('tr',
            html_writer::tag('td', get_string('learningpathnoactivities', 'format_selfstudy'), ['colspan' => 2]));
    }

    return html_writer::div(
        html_writer::tag('h3', get_string('learningpathsyncactivitydiagnosis', 'format_selfstudy')) .
        html_writer::tag('table',
            html_writer::tag('thead', html_writer::tag('tr',
                html_writer::tag('th', get_string('learningpathactivity', 'format_selfstudy')) .
                html_writer::tag('th', get_string('learningpathsyncfacts', 'format_selfstudy')))) .
            html_writer::tag('tbody', $rows),
            ['class' => 'generaltable format-selfstudy-pathdiagnosis-table']),
        'format-selfstudy-pathdiagnosis-panel'
    );
}

/**
 * Renders planned sync rules.
 *
 * @param stdClass $diagnosis
 * @return string
 */
function format_selfstudy_path_diagnosis_render_rules(stdClass $diagnosis): string {
    $rows = '';
    foreach ($diagnosis->rules as $rule) {
        $source = s($rule->source->label);
        if (!empty($rule->source->children)) {
            $childlabels = array_map(static function(stdClass $child): string {
                return s($child->label);
            }, $rule->source->children);
            $source .= html_writer::div(implode(', ', $childlabels), 'text-muted small');
        }
        if (!empty($rule->context)) {
            $source .= html_writer::div(s($rule->context), 'text-muted small');
        }

        $status = $rule->translatable ?
            format_selfstudy_path_diagnosis_badge(get_string('learningpathsynctranslatable', 'format_selfstudy'), 'success') :
            format_selfstudy_path_diagnosis_badge(get_string('learningpathsyncnottranslatable', 'format_selfstudy'), 'danger');
        if (!empty($rule->rulekind)) {
            $status .= html_writer::div(s($rule->rulekind), 'text-muted small');
        }
        if (!$rule->translatable && $rule->reason !== '') {
            $status .= html_writer::div(s($rule->reason), 'text-muted small');
        } else if (!empty($rule->existingtargetavailability)) {
            $status .= html_writer::div(get_string('learningpathsyncexistingrulewillbereplaced', 'format_selfstudy'),
                'text-muted small');
        }

        $planned = $rule->availabilityjson !== '' ?
            html_writer::div(format_selfstudy_path_diagnosis_render_rule_summary($rule), 'small') .
            html_writer::tag('code', s($rule->availabilityjson)) :
            html_writer::span(get_string('learningpathsyncnorule', 'format_selfstudy'), 'text-muted');

        $rows .= html_writer::tag('tr',
            html_writer::tag('td', $source) .
            html_writer::tag('td', s($rule->target->label)) .
            html_writer::tag('td', $status) .
            html_writer::tag('td', $planned)
        );
    }

    if ($rows === '') {
        $rows = html_writer::tag('tr',
            html_writer::tag('td', get_string('learningpathsyncnorules', 'format_selfstudy'), ['colspan' => 4]));
    }

    return html_writer::div(
        html_writer::tag('h3', get_string('learningpathsyncrules', 'format_selfstudy')) .
        html_writer::tag('table',
            html_writer::tag('thead', html_writer::tag('tr',
                html_writer::tag('th', get_string('learningpathsyncsource', 'format_selfstudy')) .
                html_writer::tag('th', get_string('learningpathsynctarget', 'format_selfstudy')) .
                html_writer::tag('th', get_string('learningpathsyncstatus', 'format_selfstudy')) .
                html_writer::tag('th', get_string('learningpathsyncplannedrule', 'format_selfstudy')))) .
            html_writer::tag('tbody', $rows),
            ['class' => 'generaltable format-selfstudy-pathdiagnosis-table']),
        'format-selfstudy-pathdiagnosis-panel'
    );
}

/**
 * Renders a human-readable planned rule summary.
 *
 * @param stdClass $rule
 * @return string
 */
function format_selfstudy_path_diagnosis_render_rule_summary(stdClass $rule): string {
    $source = !empty($rule->source->children) ?
        implode(', ', array_map(static function(stdClass $child): string {
            return $child->label;
        }, $rule->source->children)) :
        $rule->source->label;

    return s(get_string($rule->requireany ? 'learningpathsyncrulesummaryany' : 'learningpathsyncrulesummaryall',
        'format_selfstudy', (object)[
            'source' => $source,
            'target' => $rule->target->label,
        ]));
}

/**
 * Renders a compact diagnosis badge.
 *
 * @param string $label
 * @param string $state
 * @return string
 */
function format_selfstudy_path_diagnosis_badge(string $label, string $state): string {
    return html_writer::span(s($label), 'format-selfstudy-pathdiagnosis-badge format-selfstudy-pathdiagnosis-' . $state);
}
