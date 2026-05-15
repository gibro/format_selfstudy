<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Renders the core learner-facing base experience for published learning paths.
 */
class base_renderer {

    /**
     * Renders the learner's active learning path choice and progress.
     *
     * @param \stdClass $course
     * @param \stdClass[] $paths
     * @param \stdClass|null $activepath
     * @param \stdClass|null $progress
     * @param \stdClass[] $outline
     * @param string $pathpointcolor
     * @return string
     */
    public function render_path_choice(\stdClass $course, array $paths, ?\stdClass $activepath,
            ?\stdClass $progress, array $outline = [], string $pathpointcolor = '#6f1ab1'): string {
        $pathpointcolor = \format_selfstudy_normalise_hex_color($pathpointcolor);
        $output = \html_writer::start_tag('details', [
            'class' => 'format-selfstudy-pathchoice',
            'open' => 'open',
            'style' => '--format-selfstudy-path-color: ' . $pathpointcolor,
        ]);
        $output .= \html_writer::tag('summary', get_string('learningpathcurrent', 'format_selfstudy'),
            ['class' => 'format-selfstudy-pathchoice-summary']);

        if ($activepath && $progress) {
            $progresslabel = get_string('learningpathprogresscount', 'format_selfstudy', (object)[
                'complete' => $progress->complete,
                'total' => $progress->total,
                'percentage' => $progress->percentage,
            ]);

            $output .= \html_writer::div(format_string($activepath->name), 'format-selfstudy-pathchoice-name');
            $output .= \html_writer::div($progresslabel, 'format-selfstudy-pathchoice-progresslabel');
            $output .= \html_writer::start_div('format-selfstudy-pathchoice-meter', [
                'role' => 'progressbar',
                'aria-valuemin' => 0,
                'aria-valuemax' => 100,
                'aria-valuenow' => $progress->percentage,
                'aria-label' => get_string('learningpathprogress', 'format_selfstudy'),
            ]);
            $output .= \html_writer::div('', 'format-selfstudy-pathchoice-meterbar',
                ['style' => 'width: ' . $progress->percentage . '%']);
            $output .= \html_writer::end_div();

            if ($outline) {
                $output .= $this->render_path_outline($outline, 'format-selfstudy-pathoutline', $pathpointcolor);
            }
        } else {
            $output .= \html_writer::div(get_string('learningpathchooseintro', 'format_selfstudy'),
                'format-selfstudy-pathchoice-intro');
        }

        if ($paths) {
            $output .= \html_writer::start_tag('form', [
                'method' => 'post',
                'action' => (new \moodle_url('/course/format/selfstudy/path_select.php'))->out(false),
                'class' => 'format-selfstudy-pathchoice-form',
            ]);
            $output .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $course->id]);
            $output .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $output .= \html_writer::label(get_string('learningpathselect', 'format_selfstudy'),
                'format-selfstudy-pathchoice-select');

            $options = [];
            foreach ($paths as $path) {
                $options[(int)$path->id] = format_string($path->name);
            }
            $selected = ($activepath && (int)($activepath->userid ?? 0) === 0) ? (int)$activepath->id : 0;
            $output .= \html_writer::select($options, 'pathid', $selected, false, [
                'id' => 'format-selfstudy-pathchoice-select',
                'class' => 'custom-select',
            ]);
            $output .= \html_writer::empty_tag('input', [
                'type' => 'submit',
                'class' => 'btn btn-secondary',
                'value' => get_string('learningpathselectbutton', 'format_selfstudy'),
            ]);
            $output .= \html_writer::end_tag('form');
        }
        $output .= \html_writer::end_tag('details');

        return $output;
    }

    /**
     * Renders the learner's recent and upcoming path movement.
     *
     * @param \stdClass[] $outline
     * @return string
     */
    public function render_learning_trace(array $outline): string {
        $completed = array_values(array_filter($outline, static function(\stdClass $entry): bool {
            return $entry->status === 'complete' && !empty($entry->url);
        }));
        usort($completed, static function(\stdClass $left, \stdClass $right): int {
            return ((int)($right->timemodified ?? 0) <=> (int)($left->timemodified ?? 0));
        });

        $upcoming = array_values(array_filter($outline, static function(\stdClass $entry): bool {
            return in_array($entry->status, ['recommended', 'started', 'review', 'open'], true) && !empty($entry->url);
        }));

        $output = \html_writer::start_tag('section', ['class' => 'format-selfstudy-learningtrace']);
        $output .= \html_writer::tag('h3', get_string('learningpathtrace', 'format_selfstudy'));
        $output .= \html_writer::start_div('format-selfstudy-learningtrace-grid');
        $output .= $this->render_learning_trace_list(
            get_string('learningpathtracecompleted', 'format_selfstudy'),
            array_slice($completed, 0, 5),
            'complete'
        );
        $output .= $this->render_learning_trace_list(
            get_string('learningpathtracenext', 'format_selfstudy'),
            array_slice($upcoming, 0, 5),
            'next'
        );
        $output .= \html_writer::end_div();
        $output .= \html_writer::end_tag('section');

        return $output;
    }

    /**
     * Renders one trace list.
     *
     * @param string $title
     * @param \stdClass[] $items
     * @param string $type
     * @return string
     */
    public function render_learning_trace_list(string $title, array $items, string $type): string {
        $output = \html_writer::start_div('format-selfstudy-learningtrace-panel format-selfstudy-learningtrace-' .
            $type);
        $output .= \html_writer::tag('h4', $title);

        if (!$items) {
            $output .= \html_writer::div(get_string('learningpathtraceempty', 'format_selfstudy'), 'text-muted');
            $output .= \html_writer::end_div();
            return $output;
        }

        $entries = [];
        foreach ($items as $entry) {
            $statuslabel = get_string('learningpathstatus' . $entry->status, 'format_selfstudy');
            $entries[] = \html_writer::tag('li',
                \html_writer::span($statuslabel, 'format-selfstudy-learningtrace-status') .
                \html_writer::span(\html_writer::link($entry->url, $entry->title),
                    'format-selfstudy-learningtrace-title')
            );
        }

        $output .= \html_writer::tag('ol', implode('', $entries), ['class' => 'format-selfstudy-learningtrace-list']);
        $output .= \html_writer::end_div();
        return $output;
    }

    /**
     * Renders the active path as an explicit accessible alternative to visual experiences.
     *
     * @param \stdClass $activepath
     * @param \stdClass|null $progress
     * @param \stdClass[] $outline
     * @param \cm_info|null $mainmapcm
     * @param string $pathpointcolor
     * @param bool $showlockedactivities
     * @return string
     */
    public function render_accessible_path_view(\stdClass $activepath, ?\stdClass $progress, array $outline,
            ?\cm_info $mainmapcm, string $pathpointcolor = '#6f1ab1', bool $showlockedactivities = true): string {
        $outline = \format_selfstudy_filter_locked_outline($outline, $showlockedactivities);
        $nav = $this->get_path_outline_navigation($outline);
        $titleid = 'format-selfstudy-accessiblepath-title';
        $pathpointcolor = \format_selfstudy_normalise_hex_color($pathpointcolor);

        $output = \html_writer::start_tag('section', [
            'class' => 'format-selfstudy-accessiblepath',
            'aria-labelledby' => $titleid,
            'style' => '--format-selfstudy-path-color: ' . $pathpointcolor,
        ]);
        $output .= \html_writer::tag('h3', get_string('learningpathaccessibleview', 'format_selfstudy'),
            ['id' => $titleid]);
        $output .= \html_writer::div(get_string('learningpathaccessibleintro', 'format_selfstudy'), 'text-muted');

        if ($progress) {
            $progresslabel = get_string('learningpathprogresscount', 'format_selfstudy', (object)[
                'complete' => $progress->complete,
                'total' => $progress->total,
                'percentage' => $progress->percentage,
            ]);
            $output .= \html_writer::div($progresslabel, 'format-selfstudy-accessiblepath-progress');
        }

        $output .= \html_writer::start_tag('nav', [
            'class' => 'format-selfstudy-accessiblepath-nav',
            'aria-label' => get_string('learningpathaccessiblenavigation', 'format_selfstudy'),
        ]);
        if (!empty($nav['previous'])) {
            $output .= \html_writer::link($nav['previous']->url,
                get_string('learningpathaccessibleprevious', 'format_selfstudy'), ['class' => 'btn btn-secondary']);
        }
        if (!empty($activepath->courseid)) {
            $output .= \html_writer::link(new \moodle_url('/course/view.php', ['id' => (int)$activepath->courseid]),
                get_string('viewcourse', 'format_selfstudy'), ['class' => 'btn btn-secondary']);
        }
        if ($mainmapcm) {
            $output .= \html_writer::link(new \moodle_url('/mod/learningmap/view.php', ['id' => $mainmapcm->id]),
                get_string('learningpathaccessiblemap', 'format_selfstudy'), ['class' => 'btn btn-secondary']);
        }
        if (!empty($nav['next'])) {
            $output .= \html_writer::link($nav['next']->url,
                get_string('learningpathaccessiblenext', 'format_selfstudy'), ['class' => 'btn btn-primary']);
        }
        $output .= \html_writer::end_tag('nav');

        $output .= $this->render_path_outline($outline, 'format-selfstudy-accessiblepath-list', $pathpointcolor);
        $output .= \html_writer::end_tag('section');

        return $output;
    }

    /**
     * Finds previous and next stations for accessible path navigation.
     *
     * @param \stdClass[] $outline
     * @return array
     */
    public function get_path_outline_navigation(array $outline): array {
        $navigation = ['previous' => null, 'next' => null];

        foreach ($outline as $entry) {
            if (empty($entry->url)) {
                continue;
            }
            if ($entry->status === 'complete') {
                $navigation['previous'] = $entry;
                continue;
            }
            if (!$navigation['next'] && in_array($entry->status, ['recommended', 'started', 'review', 'open'], true)) {
                $navigation['next'] = $entry;
            }
        }

        return $navigation;
    }

    /**
     * Renders a compact learning path outline.
     *
     * @param \stdClass[] $outline
     * @param string $class
     * @param string $pathpointcolor
     * @return string
     */
    public function render_path_outline(array $outline, string $class = 'format-selfstudy-pathoutline',
            string $pathpointcolor = '#6f1ab1'): string {
        $items = [];
        $pathpointcolor = \format_selfstudy_normalise_hex_color($pathpointcolor);

        $count = count($outline);
        for ($index = 0; $index < $count; $index++) {
            $entry = $outline[$index];
            $alternativegroup = (string)($entry->alternativegroup ?? '');
            if ($alternativegroup === '') {
                $items[] = $this->render_path_outline_entry($entry);
                continue;
            }

            $groupitems = [];
            while ($index < $count && (string)($outline[$index]->alternativegroup ?? '') === $alternativegroup) {
                $groupitems[] = $this->render_path_outline_entry($outline[$index], false);
                $index++;
            }
            $index--;

            $items[] = \html_writer::tag('li',
                \html_writer::div(get_string('learningpathalternatives', 'format_selfstudy'),
                    'format-selfstudy-pathoutline-alternativeheading') .
                \html_writer::tag('ol', implode('', $groupitems), [
                    'class' => 'format-selfstudy-pathoutline-alternatives',
                ]),
                [
                    'class' => 'format-selfstudy-pathoutline-alternativegroup',
                    'style' => 'margin-left: ' . ((int)$entry->level * 1.25) . 'rem',
                ]
            );
        }

        return \html_writer::tag('ol', implode('', $items), [
            'class' => $class,
            'aria-label' => get_string('learningpathaccessibleoutline', 'format_selfstudy'),
            'data-format-selfstudy-outline' => '1',
            'style' => '--format-selfstudy-path-color: ' . $pathpointcolor,
        ]);
    }

    /**
     * Renders a single learning path outline entry.
     *
     * @param \stdClass $entry
     * @param bool $uselevelmargin
     * @return string
     */
    public function render_path_outline_entry(\stdClass $entry, bool $uselevelmargin = true): string {
        $statuslabel = $this->get_path_status_label($entry);
        $entrytitle = format_string((string)$entry->title);
        $title = $entry->url ? \html_writer::link($entry->url, $entrytitle) : s($entrytitle);
        $availableinfo = self::normalise_plain_text((string)($entry->availableinfo ?? ''));
        $description = $availableinfo !== '' ?
            \html_writer::div(s($availableinfo), 'format-selfstudy-pathoutline-info') : '';
        $competencies = array_values(array_filter((array)($entry->competencies ?? [])));
        if ($competencies) {
            $description .= \html_writer::div(
                s(get_string('activitycompetencies', 'format_selfstudy') . ': ' . implode(', ', $competencies)),
                'format-selfstudy-pathoutline-info format-selfstudy-pathoutline-competencies'
            );
        }
        $dotcontent = '';
        if ($entry->status !== 'complete' && !empty($entry->iconurl)) {
            $dotcontent = \html_writer::empty_tag('img', [
                'src' => $entry->iconurl,
                'alt' => '',
                'class' => 'format-selfstudy-pathoutline-icon',
            ]);
        }

        $classes = [
            'format-selfstudy-pathoutline-item',
            'format-selfstudy-pathoutline-' . $entry->status,
            'format-selfstudy-pathoutline-type-' . $entry->type,
        ];
        if (!empty($entry->milestonechild)) {
            $classes[] = 'format-selfstudy-pathoutline-milestonechild';
        }
        if (!empty($entry->milestonegroupstart)) {
            $classes[] = 'format-selfstudy-pathoutline-groupstart';
        }
        if (!empty($entry->milestonegroupend)) {
            $classes[] = 'format-selfstudy-pathoutline-groupend';
        }
        if (!empty($entry->milestonecomplete)) {
            $classes[] = 'format-selfstudy-pathoutline-milestonecomplete';
        }
        if (!empty($entry->alternativechoice)) {
            $classes[] = 'format-selfstudy-pathoutline-alternativechoice';
        }

        return \html_writer::tag('li',
            \html_writer::span($dotcontent, 'format-selfstudy-pathoutline-dot', ['aria-hidden' => 'true']) .
            \html_writer::span($title, 'format-selfstudy-pathoutline-title') .
            \html_writer::span($statuslabel, 'format-selfstudy-pathoutline-status') .
            $description .
            (!empty($entry->actionurl) ? \html_writer::link($entry->actionurl, $entry->actionlabel,
                [
                    'class' => 'btn btn-secondary btn-sm format-selfstudy-pathoutline-action',
                    'aria-label' => $entry->actionlabel . ': ' . $entry->title,
                ]) : ''),
            [
                'class' => implode(' ', $classes),
                'style' => 'margin-left: ' . ($uselevelmargin ? ((int)$entry->level * 1.25) : 0) . 'rem',
                'tabindex' => 0,
                'data-format-selfstudy-outline-item' => '1',
                'aria-label' => $this->get_path_item_aria_label($entry, $statuslabel),
            ]
        );
    }

    /**
     * Converts Moodle-generated snippets into readable one-line text.
     *
     * @param string $text
     * @return string
     */
    private static function normalise_plain_text(string $text): string {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $text = preg_replace('/<\s*br\s*\/?\s*>/i', ' ', $text);
        $text = preg_replace('/<\/(p|div|li|ul|ol|section|article|h[1-6])\s*>/i', ' ', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Returns a localized path status label.
     *
     * @param \stdClass $entry
     * @return string
     */
    public function get_path_status_label(\stdClass $entry): string {
        return get_string('learningpathstatus' . $entry->status, 'format_selfstudy');
    }

    /**
     * Returns a compact screen reader label for one path item.
     *
     * @param \stdClass $entry
     * @param string $statuslabel
     * @return string
     */
    public function get_path_item_aria_label(\stdClass $entry, string $statuslabel): string {
        $parts = [
            $entry->title,
            get_string('learningpathaccessiblestatus', 'format_selfstudy', $statuslabel),
        ];
        if (!empty($entry->availableinfo)) {
            $parts[] = self::normalise_plain_text((string)$entry->availableinfo);
        }
        if (!empty($entry->competencies)) {
            $parts[] = get_string('activitycompetencies', 'format_selfstudy') . ': ' .
                implode(', ', array_map('strip_tags', (array)$entry->competencies));
        }

        return implode('. ', array_filter($parts));
    }
}
