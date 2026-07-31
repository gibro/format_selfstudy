<?php
// This file is part of Moodle - http://moodle.org/

namespace selfstudyexperience_escape;

defined('MOODLE_INTERNAL') || die();

/**
 * Teacher-facing guide for turning a course into an escape room.
 *
 * A compact, hands-on reference rendered from language strings. The concepts
 * follow the edu-breakout approach (see the source link rendered at the
 * bottom): rooms, riddles, collectable items and code locks — all built from
 * plain Moodle completion and availability, without any extra engine.
 */
class guide {

    /**
     * Renders the full guide.
     *
     * @param \stdClass $course
     * @return string
     */
    public function render(\stdClass $course): string {
        $output = \html_writer::start_div('format-selfstudy-escapeguide');
        $output .= $this->section('concept', $this->render_concept_list());
        $output .= $this->section('dramaturgy', $this->render_dramaturgy());
        $output .= $this->section('h5p', $this->render_h5p_list());
        $output .= $this->section('codes', $this->render_code_list());
        $output .= $this->section('catalog', $this->render_catalog());
        $output .= $this->section('doors', $this->render_doors($course));
        $output .= $this->section('material', $this->render_material());
        $output .= $this->section('checklist', $this->render_checklist());
        $output .= \html_writer::div(
            get_string('escapeguide_source', 'format_selfstudy',
                'https://bildungssprit.de/blog/digitale-escaperooms-in-moodle-gestalten-ein-kurs-zur-erstellung-von-edu-breakouts'),
            'format-selfstudy-escapeguide-source'
        );
        $output .= \html_writer::end_div();

        return $output;
    }

    /**
     * Renders one titled guide section.
     *
     * @param string $key
     * @param string $body
     * @return string
     */
    private function section(string $key, string $body): string {
        return \html_writer::tag('section',
            \html_writer::tag('h3', get_string('escapeguide_' . $key, 'format_selfstudy')) .
            \html_writer::div(get_string('escapeguide_' . $key . '_intro', 'format_selfstudy'),
                'format-selfstudy-escapeguide-intro') .
            $body,
            ['class' => 'format-selfstudy-escapeguide-section']
        );
    }

    /**
     * Renders the concept mapping list.
     *
     * @return string
     */
    private function render_concept_list(): string {
        $rows = [];
        foreach (['rooms', 'stations', 'doors', 'riddles', 'items', 'inventory'] as $key) {
            $rows[] = \html_writer::tag('strong', get_string('escapeguide_concept_' . $key, 'format_selfstudy')) .
                ' — ' . get_string('escapeguide_concept_' . $key . '_desc', 'format_selfstudy');
        }

        return \html_writer::alist($rows, ['class' => 'format-selfstudy-escapeguide-list'], 'ul');
    }

    /**
     * Renders the linear vs non-linear dramaturgy comparison.
     *
     * @return string
     */
    private function render_dramaturgy(): string {
        $cards = '';
        foreach (['linear', 'nonlinear', 'codeparts'] as $key) {
            $cards .= \html_writer::div(
                \html_writer::tag('h4', get_string('escapeguide_dramaturgy_' . $key, 'format_selfstudy')) .
                \html_writer::div(get_string('escapeguide_dramaturgy_' . $key . '_desc', 'format_selfstudy')),
                'format-selfstudy-escapeguide-card'
            );
        }

        return \html_writer::div($cards, 'format-selfstudy-escapeguide-cards');
    }

    /**
     * Renders the H5P content type recommendations.
     *
     * @return string
     */
    private function render_h5p_list(): string {
        $rows = [];
        foreach (['imagehotspots', 'findhotspot', 'virtualtour', 'branching', 'sequencing'] as $key) {
            $rows[] = \html_writer::tag('strong', get_string('escapeguide_h5p_' . $key, 'format_selfstudy')) .
                ' — ' . get_string('escapeguide_h5p_' . $key . '_desc', 'format_selfstudy');
        }

        return \html_writer::alist($rows, ['class' => 'format-selfstudy-escapeguide-list'], 'ul');
    }

    /**
     * Renders the code entry content types.
     *
     * @return string
     */
    private function render_code_list(): string {
        $rows = [];
        foreach (['combinationlock', 'fillinblanks', 'dragwords', 'flashcards', 'draganddrop', 'speakwords'] as $key) {
            $rows[] = \html_writer::tag('strong', get_string('escapeguide_code_' . $key, 'format_selfstudy')) .
                ' — ' . get_string('escapeguide_code_' . $key . '_desc', 'format_selfstudy');
        }

        return \html_writer::alist($rows, ['class' => 'format-selfstudy-escapeguide-list'], 'ul');
    }

    /**
     * Renders the riddle catalog table.
     *
     * @return string
     */
    private function render_catalog(): string {
        $head = \html_writer::tag('tr',
            \html_writer::tag('th', get_string('escapeguide_catalog_colriddle', 'format_selfstudy')) .
            \html_writer::tag('th', get_string('escapeguide_catalog_colexperience', 'format_selfstudy')) .
            \html_writer::tag('th', get_string('escapeguide_catalog_coltool', 'format_selfstudy')) .
            \html_writer::tag('th', get_string('escapeguide_catalog_colsetup', 'format_selfstudy'))
        );

        $rows = '';
        $keys = ['quizlock', 'codelock', 'hotspotsearch', 'tour', 'collectitem', 'codeparts', 'matching',
            'sequence', 'speak', 'nofm'];
        foreach ($keys as $key) {
            $rows .= \html_writer::tag('tr',
                \html_writer::tag('td', \html_writer::tag('strong',
                    get_string('escapeguide_riddle_' . $key, 'format_selfstudy'))) .
                \html_writer::tag('td', get_string('escapeguide_riddle_' . $key . '_experience', 'format_selfstudy')) .
                \html_writer::tag('td', get_string('escapeguide_riddle_' . $key . '_tool', 'format_selfstudy')) .
                \html_writer::tag('td', get_string('escapeguide_riddle_' . $key . '_setup', 'format_selfstudy'))
            );
        }

        return \html_writer::div(
            \html_writer::tag('table', \html_writer::tag('thead', $head) . \html_writer::tag('tbody', $rows), [
                'class' => 'generaltable format-selfstudy-escapeguide-table',
            ]),
            'format-selfstudy-escapeguide-tablewrap'
        );
    }

    /**
     * Renders the transitions/doors how-to.
     *
     * @param \stdClass $course
     * @return string
     */
    private function render_doors(\stdClass $course): string {
        $editorurl = new \moodle_url('/course/format/selfstudy/path_editor.php', ['id' => (int)$course->id]);

        return \html_writer::alist([
            get_string('escapeguide_doors_editor', 'format_selfstudy'),
            get_string('escapeguide_doors_manual', 'format_selfstudy'),
            get_string('escapeguide_doors_source', 'format_selfstudy'),
        ], ['class' => 'format-selfstudy-escapeguide-list'], 'ul') .
        \html_writer::div(
            \html_writer::link($editorurl, get_string('learningpatheditor', 'format_selfstudy'),
                ['class' => 'btn btn-secondary']),
            'format-selfstudy-escapeguide-actions'
        );
    }

    /**
     * Renders material tips.
     *
     * @return string
     */
    private function render_material(): string {
        return \html_writer::alist([
            get_string('escapeguide_material_cc0', 'format_selfstudy'),
            get_string('escapeguide_material_share', 'format_selfstudy'),
            get_string('escapeguide_material_example', 'format_selfstudy'),
            get_string('escapeguide_material_h5penable', 'format_selfstudy'),
        ], ['class' => 'format-selfstudy-escapeguide-list'], 'ul');
    }

    /**
     * Renders the five-step quick start checklist.
     *
     * @return string
     */
    private function render_checklist(): string {
        $steps = [];
        for ($i = 1; $i <= 5; $i++) {
            $steps[] = get_string('escapeguide_checklist_step' . $i, 'format_selfstudy');
        }

        return \html_writer::alist($steps, ['class' => 'format-selfstudy-escapeguide-checklist'], 'ol');
    }
}
