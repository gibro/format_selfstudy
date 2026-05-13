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

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);
require_login($course);

$coursecontext = context_course::instance($course->id);
require_capability('moodle/course:update', $coursecontext);

$format = course_get_format($course);
if ($format->get_format() !== 'selfstudy') {
    throw new moodle_exception('invalidcourseformat', 'error');
}

$url = new moodle_url('/course/format/selfstudy/experience_settings.php', ['id' => $course->id]);
$repository = new \format_selfstudy\local\experience_repository();
$registry = new \format_selfstudy\local\experience_registry($repository);
$migrator = new \format_selfstudy\local\learningmap_config_migrator($repository);
$migrator->mirror_course((int)$course->id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $components = optional_param_array('components', [], PARAM_COMPONENT);
    $enabled = optional_param_array('enabled', [], PARAM_BOOL);
    $sortorders = optional_param_array('sortorder', [], PARAM_INT);
    $configs = optional_param_array('configjson', [], PARAM_RAW);
    $schemas = optional_param_array('configschema', [], PARAM_INT);
    $missing = optional_param_array('missing', [], PARAM_BOOL);
    $learningmapmain = optional_param('learningmap_mainmapcmid', 0, PARAM_INT);
    $learningmapsectionmapsenabled = optional_param('learningmap_sectionmapsenabled', 0, PARAM_BOOL);
    $learningmapavatarenabled = optional_param('learningmap_avatarenabled', 0, PARAM_BOOL);
    $learningmapsectionmaps = optional_param_array('learningmap_sectionmap', [], PARAM_INT);

    foreach ($components as $index => $component) {
        if ($component === \format_selfstudy\local\learningmap_config_migrator::COMPONENT) {
            $sectionmaps = [];
            foreach ($learningmapsectionmaps as $sectionid => $cmid) {
                if ((int)$sectionid > 0 && (int)$cmid > 0) {
                    $sectionmaps[(int)$sectionid] = (int)$cmid;
                }
            }
            $config = [
                'mainmapcmid' => $learningmapmain,
                'sectionmaps' => (object)$sectionmaps,
                'sectionmapsenabled' => !empty($learningmapsectionmapsenabled),
                'avatarenabled' => !empty($learningmapavatarenabled),
                'fullscreenenabled' => true,
                'legacyformatoptions' => [
                    'mainlearningmap' => $learningmapmain,
                    'enablesectionmaps' => !empty($learningmapsectionmapsenabled),
                    'enableavatar' => !empty($learningmapavatarenabled),
                ],
            ];
        } else {
            $config = json_decode((string)($configs[$index] ?? '{}'), true);
            if (!is_array($config) || json_last_error() !== JSON_ERROR_NONE) {
                $config = [];
            }
        }

        $ismissing = !empty($missing[$index]);
        $repository->save_course_experience((int)$course->id, $component, $config,
            !$ismissing && !empty($enabled[$index]), (int)($sortorders[$index] ?? $index),
            max(1, (int)($schemas[$index] ?? 1)), $ismissing);
    }

    redirect($url, get_string('experiencesettingssaved', 'format_selfstudy'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

$PAGE->set_url($url);
$PAGE->set_context($coursecontext);
$PAGE->set_course($course);
$PAGE->set_pagelayout('course');
$PAGE->set_title(get_string('experiencesettings', 'format_selfstudy'));
$PAGE->set_heading(format_string($course->fullname));

$entries = $registry->get_course_experiences($course);
$modinfo = get_fast_modinfo($course);
$learningmapoptions = [0 => get_string('nolearningmap', 'format_selfstudy')];
foreach (\format_selfstudy\local\learningmap_config_migrator::get_visible_map_cms($modinfo) as $cm) {
    $learningmapoptions[(int)$cm->id] = format_string($cm->name, true);
}
$sections = array_filter($modinfo->get_section_info_all(), static function(section_info $section): bool {
    return $section->section > 0 && $section->uservisible;
});

echo $OUTPUT->header();
echo html_writer::start_div('format-selfstudy-experience-settings');
echo $OUTPUT->heading(get_string('experiencesettings', 'format_selfstudy'), 2);
echo html_writer::div(get_string('experienceintro', 'format_selfstudy'), 'alert alert-info');

echo html_writer::start_div('format-selfstudy-patheditor-toolbar');
echo html_writer::link(new moodle_url('/course/format/selfstudy/path_editor.php', ['id' => $course->id]),
    get_string('learningpatheditor', 'format_selfstudy'), ['class' => 'btn btn-secondary']);
echo html_writer::link(new moodle_url('/course/view.php', ['id' => $course->id]),
    get_string('viewcourse', 'format_selfstudy'), ['class' => 'btn btn-secondary']);
echo html_writer::end_div();

if (!$entries) {
    echo $OUTPUT->notification(get_string('experiencenoneavailable', 'format_selfstudy'),
        \core\output\notification::NOTIFY_INFO);
} else {
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $url->out(false),
        'class' => 'format-selfstudy-experience-form',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    echo html_writer::start_tag('table', ['class' => 'generaltable']);
    echo html_writer::start_tag('thead');
    echo html_writer::tag('tr',
        html_writer::tag('th', get_string('experience', 'format_selfstudy')) .
        html_writer::tag('th', get_string('status')) .
        html_writer::tag('th', get_string('experienceenabled', 'format_selfstudy')) .
        html_writer::tag('th', get_string('sortorder', 'format_selfstudy'))
    );
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach (array_values($entries) as $index => $entry) {
        $configjson = json_encode($entry->config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($configjson === false) {
            $configjson = '{}';
        }

        $enabledattrs = [
            'type' => 'checkbox',
            'name' => 'enabled[' . $index . ']',
            'value' => 1,
        ];
        if ($entry->enabled && !$entry->missing) {
            $enabledattrs['checked'] = 'checked';
        }
        if ($entry->missing) {
            $enabledattrs['disabled'] = 'disabled';
        }

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td',
            html_writer::tag('strong', format_string($entry->name)) .
            html_writer::div(s($entry->component), 'text-muted') .
            ($entry->description !== '' ? html_writer::div(format_text($entry->description, FORMAT_PLAIN),
                'text-muted') : '') .
            ($entry->component === \format_selfstudy\local\learningmap_config_migrator::COMPONENT ?
                format_selfstudy_render_learningmap_experience_settings($entry->config, $learningmapoptions,
                    $sections, $course) : '')
        );
        echo html_writer::tag('td', get_string('experiencestatus' . $entry->status, 'format_selfstudy'));
        echo html_writer::tag('td',
            html_writer::empty_tag('input', $enabledattrs) .
            html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'components[' . $index . ']',
                'value' => $entry->component,
            ]) .
            html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'configjson[' . $index . ']',
                'value' => $configjson,
            ]) .
            html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'configschema[' . $index . ']',
                'value' => $entry->schema,
            ]) .
            html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'missing[' . $index . ']',
                'value' => $entry->missing ? 1 : 0,
            ])
        );
        echo html_writer::tag('td', html_writer::empty_tag('input', [
            'type' => 'number',
            'name' => 'sortorder[' . $index . ']',
            'value' => $entry->sortorder,
            'class' => 'form-control',
            'min' => 0,
            'step' => 1,
        ]));
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-primary',
        'value' => get_string('savechanges'),
    ]);
    echo html_writer::end_tag('form');
}

echo html_writer::end_div();
echo $OUTPUT->footer();

/**
 * Renders the first Learningmap experience settings block.
 *
 * @param stdClass $config
 * @param array $learningmapoptions
 * @param section_info[] $sections
 * @param stdClass $course
 * @return string
 */
function format_selfstudy_render_learningmap_experience_settings(stdClass $config, array $learningmapoptions,
        array $sections, stdClass $course): string {
    $sectionmaps = (array)($config->sectionmaps ?? []);

    $output = html_writer::start_div('format-selfstudy-learningmap-settings');
    $output .= html_writer::tag('label', get_string('mainlearningmap', 'format_selfstudy'), [
        'for' => 'format-selfstudy-learningmap-mainmap',
    ]);
    $output .= html_writer::select($learningmapoptions, 'learningmap_mainmapcmid',
        (int)($config->mainmapcmid ?? 0), false, [
            'id' => 'format-selfstudy-learningmap-mainmap',
            'class' => 'custom-select',
        ]);

    $output .= html_writer::div(
        html_writer::label(
            html_writer::empty_tag('input', [
                'type' => 'checkbox',
                'name' => 'learningmap_sectionmapsenabled',
                'value' => 1,
            ] + (!empty($config->sectionmapsenabled) ? ['checked' => 'checked'] : [])) .
            ' ' . get_string('enablesectionmaps', 'format_selfstudy'),
            ''
        ),
        'form-check'
    );
    $output .= html_writer::div(
        html_writer::label(
            html_writer::empty_tag('input', [
                'type' => 'checkbox',
                'name' => 'learningmap_avatarenabled',
                'value' => 1,
            ] + (!empty($config->avatarenabled) ? ['checked' => 'checked'] : [])) .
            ' ' . get_string('enableavatar', 'format_selfstudy'),
            ''
        ),
        'form-check'
    );

    if ($sections) {
        $rows = [];
        foreach ($sections as $section) {
            $rows[] = html_writer::tag('tr',
                html_writer::tag('td', format_string(get_section_name($course, $section))) .
                html_writer::tag('td', html_writer::select($learningmapoptions,
                    'learningmap_sectionmap[' . (int)$section->id . ']',
                    (int)($sectionmaps[(int)$section->id] ?? 0), false, ['class' => 'custom-select']))
            );
        }
        $output .= html_writer::tag('table',
            html_writer::tag('thead', html_writer::tag('tr',
                html_writer::tag('th', get_string('section')) .
                html_writer::tag('th', get_string('sectionmap', 'format_selfstudy'))
            )) .
            html_writer::tag('tbody', implode('', $rows)),
            ['class' => 'generaltable format-selfstudy-learningmap-sectionsettings']
        );
    }

    $output .= html_writer::end_div();
    return $output;
}
