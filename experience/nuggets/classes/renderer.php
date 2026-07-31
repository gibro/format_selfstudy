<?php
// This file is part of Moodle - http://moodle.org/

namespace selfstudyexperience_nuggets;

use format_selfstudy\local\experience_renderer_interface;

defined('MOODLE_INTERNAL') || die();

if (!class_exists(__NAMESPACE__ . '\\feed_builder')) {
    require_once(__DIR__ . '/feed_builder.php');
}

/**
 * Learner-facing renderer for the Learning-Nuggets selfstudy experience.
 */
class renderer implements experience_renderer_interface {

    /**
     * The nuggets view is always available: it presents the full-screen
     * Lern-Nuggets app, whose content is authored (not derived from a path).
     *
     * @param \stdClass $course
     * @param \stdClass $baseview
     * @param \stdClass $config
     * @return bool
     */
    public function supports(\stdClass $course, \stdClass $baseview, \stdClass $config): bool {
        return true;
    }

    /**
     * Renders the course entry: a branded launch card into the app, plus the
     * legacy activity feed when the course still carries a learning path.
     *
     * @param \stdClass $course
     * @param \stdClass $baseview
     * @param \stdClass $config
     * @return string
     */
    public function render_course_entry(\stdClass $course, \stdClass $baseview, \stdClass $config): string {
        $output = $this->render_launch($course);

        // Keep the activity feed as a secondary view for path-based courses.
        $model = (new feed_builder())->build($course, $baseview, $config);
        if (!empty($baseview->path) && $model->nuggetcount > 0) {
            $this->require_feed_script($course, $model);
            $output .= \html_writer::tag('section', $this->render_fragment_parts($course, $model), [
                'class' => 'format-selfstudy-nuggets format-selfstudy-nuggets-mode-feed' .
                    ($model->focusmode ? ' format-selfstudy-nuggets-focus' : ''),
            ]);
        }

        return $output;
    }

    /**
     * Renders the branded launch card that opens the full-screen app.
     *
     * @param \stdClass $course
     * @return string
     */
    private function render_launch(\stdClass $course): string {
        $appurl = new \moodle_url('/course/format/selfstudy/nuggets_app.php', ['id' => (int)$course->id]);

        return \html_writer::div(
            \html_writer::span(get_string('nuggetsexperience_name', 'format_selfstudy'),
                'format-selfstudy-nqlaunch-eyebrow') .
            \html_writer::tag('h2', get_string('nuggetsapp_launchtitle', 'format_selfstudy'),
                ['class' => 'format-selfstudy-nqlaunch-title']) .
            \html_writer::tag('p', get_string('nuggetsapp_launchintro', 'format_selfstudy'),
                ['class' => 'format-selfstudy-nqlaunch-intro']) .
            \html_writer::link($appurl, get_string('nuggetsapp_launchbutton', 'format_selfstudy'),
                ['class' => 'btn btn-primary format-selfstudy-nqlaunch-button']),
            'format-selfstudy-nqlaunch'
        );
    }

    /**
     * Renders the feed content without the outer wrapper or script setup.
     *
     * Used by the ajax endpoint to refresh the feed in place after a
     * completion change, so unlocked nuggets appear without a page reload.
     *
     * @param \stdClass $course
     * @param \stdClass $baseview
     * @param \stdClass $config
     * @return string
     */
    public function render_fragment(\stdClass $course, \stdClass $baseview, \stdClass $config): string {
        $model = (new feed_builder())->build($course, $baseview, $config);
        if ($model->nuggetcount < 1) {
            return '';
        }

        return $this->render_fragment_parts($course, $model);
    }

    /**
     * Renders progress header, feed entries and end marker.
     *
     * @param \stdClass $course
     * @param \stdClass $model
     * @return string
     */
    private function render_fragment_parts(\stdClass $course, \stdClass $model): string {
        // Every nugget lives on its own "slide": a chapter heading travels
        // inside the slide of its first nugget, so it is visible right where
        // the next-link lands instead of one screen earlier.
        $entries = '';
        $pendingchapter = '';
        foreach ($model->entries as $entry) {
            if ($entry->kind === 'chapter') {
                $pendingchapter = $this->render_chapter($entry);
                continue;
            }
            $entries .= \html_writer::div(
                $pendingchapter . $this->render_nugget($course, $entry, $model),
                'format-selfstudy-nuggets-slide');
            $pendingchapter = '';
        }

        return $this->render_hero($course, $model) .
            $this->render_progress_header($model) .
            \html_writer::div($entries, 'format-selfstudy-nuggets-feed') .
            $this->render_feed_end($model);
    }

    /**
     * Renders the course start hero of the nugget feed.
     *
     * Deliberately different from the escape hero (dark game intro) and the
     * learning map hero (adventure map): a light snack-bar look whose
     * signature is a segment strip — one segment per nugget, so the whole
     * course is visible as a row of bites before scrolling starts.
     *
     * @param \stdClass $course
     * @param \stdClass $model
     * @return string
     */
    private function render_hero(\stdClass $course, \stdClass $model): string {
        $intro = $model->introtext !== '' ? $model->introtext :
            get_string('nuggetsexperience_herodefault', 'format_selfstudy');

        $stats = \html_writer::span(
            \html_writer::span('🍱', 'format-selfstudy-nuggets-statemoji', ['aria-hidden' => 'true']) .
            \html_writer::span(get_string('nuggetsexperience_herostatnuggets', 'format_selfstudy',
                (int)$model->nuggetcount)),
            'format-selfstudy-nuggets-herostat');
        if ((int)$model->totalminutes > 0) {
            $stats .= \html_writer::span(
                \html_writer::span('⏱️', 'format-selfstudy-nuggets-statemoji', ['aria-hidden' => 'true']) .
                \html_writer::span(get_string('nuggetsexperience_herostattotal', 'format_selfstudy',
                    $this->format_duration((int)$model->totalminutes))),
                'format-selfstudy-nuggets-herostat');
        }
        $left = max(0, (int)$model->mandatorytotal - (int)$model->mandatorycomplete);
        if (!$model->iscomplete && $left > 0) {
            $leftlabel = $model->remainingminutes > 0 ?
                get_string('nuggetsexperience_herostatlefttime', 'format_selfstudy', (object)[
                    'count' => $left,
                    'duration' => $this->format_duration((int)$model->remainingminutes),
                ]) :
                get_string('nuggetsexperience_herostatleft', 'format_selfstudy', $left);
            $stats .= \html_writer::span(
                \html_writer::span('🎯', 'format-selfstudy-nuggets-statemoji', ['aria-hidden' => 'true']) .
                \html_writer::span($leftlabel),
                'format-selfstudy-nuggets-herostat');
        }

        $segments = '';
        foreach ($model->segments as $segment) {
            $classes = 'format-selfstudy-nuggets-segment format-selfstudy-nuggets-segment-' .
                clean_param((string)$segment->status, PARAM_ALPHA) .
                ($segment->optional ? ' format-selfstudy-nuggets-segment-optional' : '');
            $segments .= \html_writer::span('', $classes, ['title' => $segment->title]);
        }
        $strip = $segments === '' ? '' : \html_writer::div($segments, 'format-selfstudy-nuggets-segmentstrip', [
            'role' => 'img',
            'aria-label' => get_string('nuggetsexperience_herosegments', 'format_selfstudy', (object)[
                'complete' => (int)$model->mandatorycomplete + (int)$model->optionalcomplete,
                'total' => count($model->segments),
            ]),
        ]);

        if ($model->iscomplete) {
            $action = \html_writer::div(
                \html_writer::span('🎉', 'format-selfstudy-nuggets-statemoji', ['aria-hidden' => 'true']) .
                \html_writer::span(get_string('nuggetsexperience_herodone', 'format_selfstudy')),
                'format-selfstudy-nuggets-herodone');
        } else if ($model->currentanchor !== '') {
            $labelkey = (int)$model->mandatorycomplete + (int)$model->optionalcomplete > 0 ?
                'nuggetsexperience_herocontinue' : 'nuggetsexperience_herostart';
            $action = \html_writer::div(
                \html_writer::link('#' . $model->currentanchor, get_string($labelkey, 'format_selfstudy'), [
                    'class' => 'btn btn-primary format-selfstudy-nuggets-herobutton',
                    'data-nugget-nav' => $model->currentanchor,
                ]),
                'format-selfstudy-nuggets-heroactions');
        } else {
            // Nothing is open right now: point at the first lock instead of
            // leaving the learner without a way in.
            $hint = '';
            foreach ($model->entries as $entry) {
                if ($entry->kind === 'nugget' && $entry->islocked && $entry->availableinfo !== '') {
                    $hint = $entry->availableinfo;
                    break;
                }
            }
            $action = $hint === '' ? '' : \html_writer::div(
                \html_writer::span('🔒', 'format-selfstudy-nuggets-statemoji', ['aria-hidden' => 'true']) .
                \html_writer::span(\s($hint)),
                'format-selfstudy-nuggets-herolocknote');
        }

        return \html_writer::div(
            \html_writer::span(get_string('nuggetsexperience_name', 'format_selfstudy'),
                'format-selfstudy-nuggets-heroeyebrow') .
            \html_writer::tag('h2', format_string($course->fullname),
                ['class' => 'format-selfstudy-nuggets-herotitle']) .
            \html_writer::div(\s($intro), 'format-selfstudy-nuggets-herointro') .
            \html_writer::div($stats, 'format-selfstudy-nuggets-herostats') .
            $strip .
            $action,
            'format-selfstudy-nuggets-hero'
        );
    }

    /**
     * The feed lives on the course page itself, so no activity hint is added.
     *
     * @param \stdClass $course
     * @param \stdClass $baseview
     * @param \cm_info $cm
     * @param \stdClass $config
     * @return \stdClass|null
     */
    public function get_activity_navigation(\stdClass $course, \stdClass $baseview, \cm_info $cm,
            \stdClass $config): ?\stdClass {
        return null;
    }

    /**
     * Renders the sticky progress header.
     *
     * @param \stdClass $model
     * @return string
     */
    private function render_progress_header(\stdClass $model): string {
        $counts = \html_writer::span(get_string('nuggetsexperience_progresscount', 'format_selfstudy', (object)[
            'complete' => (int)$model->mandatorycomplete,
            'total' => (int)$model->mandatorytotal,
        ]), 'format-selfstudy-nuggets-progresscount');

        $meta = '';
        if (!$model->iscomplete && $model->remainingminutes > 0) {
            $meta .= \html_writer::span(get_string('nuggetsexperience_remainingtime', 'format_selfstudy',
                $this->format_duration((int)$model->remainingminutes)), 'format-selfstudy-nuggets-remaining');
        }
        if ((int)$model->optionaltotal > 0) {
            $meta .= \html_writer::span(get_string('nuggetsexperience_optionalcount', 'format_selfstudy', (object)[
                'complete' => (int)$model->optionalcomplete,
                'total' => (int)$model->optionaltotal,
            ]), 'format-selfstudy-nuggets-optionalcount');
        }
        if ($model->currentanchor !== '') {
            $meta .= \html_writer::link('#' . $model->currentanchor,
                get_string('nuggetsexperience_jumptocurrent', 'format_selfstudy'),
                ['class' => 'format-selfstudy-nuggets-jumplink']);
        }

        $bar = \html_writer::div(
            \html_writer::div('', 'format-selfstudy-nuggets-progressfill',
                ['style' => 'width: ' . max(0, min(100, (int)$model->percentage)) . '%']),
            'format-selfstudy-nuggets-progressbar'
        );

        return \html_writer::div(
            \html_writer::div($counts . \html_writer::div($meta, 'format-selfstudy-nuggets-progressmeta'),
                'format-selfstudy-nuggets-progresstext') . $bar,
            'format-selfstudy-nuggets-progressheader'
        );
    }

    /**
     * Renders a chapter separator coming from a path milestone.
     *
     * @param \stdClass $entry
     * @return string
     */
    private function render_chapter(\stdClass $entry): string {
        $title = $entry->title !== '' ? $entry->title :
            get_string('nuggetsexperience_chapterfallback', 'format_selfstudy', (int)$entry->number);

        return \html_writer::div(
            \html_writer::tag('h3', \s($title), ['class' => 'format-selfstudy-nuggets-chaptertitle']),
            'format-selfstudy-nuggets-chapter'
        );
    }

    /**
     * Renders one nugget card.
     *
     * @param \stdClass $course
     * @param \stdClass $nugget
     * @param \stdClass $model
     * @return string
     */
    private function render_nugget(\stdClass $course, \stdClass $nugget, \stdClass $model): string {
        $classes = 'format-selfstudy-nuggets-card format-selfstudy-nuggets-status-' .
            clean_param((string)$nugget->status, PARAM_ALPHA);
        if ($nugget->optional) {
            $classes .= ' format-selfstudy-nuggets-card-optional';
        }
        if ($nugget->iscurrent) {
            $classes .= ' format-selfstudy-nuggets-card-current';
        }
        $attributes = ['class' => $classes];
        if ($nugget->cmid) {
            $attributes['id'] = 'module-' . (int)$nugget->cmid;
        }
        if ($nugget->viewcompletion && $model->scrollcompletion) {
            // The AMD module marks these as viewed once they were read.
            $attributes['data-viewcm'] = (int)$nugget->cmid;
        }

        if (!empty($nugget->reduced)) {
            $body = \html_writer::div(
                \html_writer::span('🔒', 'format-selfstudy-nuggets-lockicon', ['aria-hidden' => 'true']) .
                \html_writer::span(\s($nugget->title), 'format-selfstudy-nuggets-reducedtitle'),
                'format-selfstudy-nuggets-reduced'
            );
            return \html_writer::tag('article', $body, $attributes);
        }

        // Labels are pure content and render inline without card chrome.
        if ($nugget->modname === 'label' && $nugget->inlinecontent !== '') {
            return \html_writer::tag('article',
                \html_writer::div($nugget->inlinecontent, 'format-selfstudy-nuggets-inline') .
                $this->render_neighbour_nav($nugget),
                ['class' => $classes . ' format-selfstudy-nuggets-card-label']);
        }

        $media = $nugget->imageurl ?
            \html_writer::empty_tag('img', ['src' => $nugget->imageurl, 'alt' => '', 'loading' => 'lazy']) :
            ($nugget->iconurl ?
                \html_writer::empty_tag('img', [
                    'src' => $nugget->iconurl,
                    'alt' => $nugget->iconalt,
                    'class' => 'format-selfstudy-nuggets-icon',
                    'loading' => 'lazy',
                ]) : '');

        $chips = \html_writer::span(get_string($nugget->optional ? 'optional' : 'required', 'format_selfstudy'),
            'format-selfstudy-nuggets-chip format-selfstudy-nuggets-chip-kind');
        if ($nugget->durationminutes > 0) {
            $chips .= \html_writer::span(\s($this->format_duration((int)$nugget->durationminutes)),
                'format-selfstudy-nuggets-chip format-selfstudy-nuggets-chip-duration');
        }
        $chips .= \html_writer::span(\s($this->status_label($nugget)),
            'format-selfstudy-nuggets-chip format-selfstudy-nuggets-chip-status');

        $body = \html_writer::div($chips, 'format-selfstudy-nuggets-cardmeta') .
            \html_writer::tag('h4', \s($nugget->title), ['class' => 'format-selfstudy-nuggets-cardtitle']);

        if ($nugget->learninggoal !== '') {
            $body .= \html_writer::div(format_text($nugget->learninggoal, FORMAT_PLAIN),
                'format-selfstudy-nuggets-goal');
        }
        if ($nugget->embedhtml !== '') {
            // The activity plays directly in the feed; the description would
            // duplicate what the content itself says.
            $body .= $nugget->embedhtml;
        } else if ($nugget->description !== '') {
            $body .= \html_writer::div($nugget->description, 'format-selfstudy-nuggets-description');
        }

        if ($nugget->islocked || $nugget->ismissing) {
            if ($nugget->availableinfo !== '') {
                $body .= \html_writer::div(
                    \html_writer::span('🔒', 'format-selfstudy-nuggets-lockicon', ['aria-hidden' => 'true']) .
                    \html_writer::span(\s($nugget->availableinfo)),
                    'format-selfstudy-nuggets-lockhint'
                );
            }
        } else {
            $body .= $this->render_actions($course, $nugget, $model);
        }
        $body .= $this->render_neighbour_nav($nugget);

        return \html_writer::tag('article',
            \html_writer::div($media, 'format-selfstudy-nuggets-cardmedia' .
                ($nugget->imageurl ? '' : ' format-selfstudy-nuggets-cardmedia-fallback')) .
            \html_writer::div($body, 'format-selfstudy-nuggets-cardbody'),
            $attributes);
    }

    /**
     * Renders the primary action and the optional manual completion toggle.
     *
     * @param \stdClass $course
     * @param \stdClass $nugget
     * @param \stdClass $model
     * @return string
     */
    private function render_actions(\stdClass $course, \stdClass $nugget, \stdClass $model): string {
        $actions = '';
        if ($nugget->url && $nugget->embedhtml !== '') {
            // Content already plays inline; the activity page stays reachable
            // as a quiet secondary link.
            $actions .= \html_writer::link($nugget->url,
                get_string('nuggetsexperience_openactivity', 'format_selfstudy'),
                ['class' => 'format-selfstudy-nuggets-activitylink']);
        } else if ($nugget->url) {
            $labelkey = $nugget->iscomplete ? 'nuggetsexperience_review' :
                ($nugget->status === 'started' ? 'nuggetsexperience_continue' : 'nuggetsexperience_open');
            $actions .= \html_writer::link($nugget->url, get_string($labelkey, 'format_selfstudy'), [
                'class' => ($nugget->iscomplete ? 'btn btn-secondary' : 'btn btn-primary') .
                    ' format-selfstudy-nuggets-openbutton',
            ]);
        }

        if ($model->manualcompletionallowed && $nugget->manualcompletion && $nugget->cmid) {
            $actions .= $this->render_manual_completion_form($course, $nugget);
        }

        if ($actions === '') {
            return '';
        }

        return \html_writer::div($actions, 'format-selfstudy-nuggets-actions');
    }

    /**
     * Renders the previous/next scroll navigation below a nugget.
     *
     * Unlike the base view, these controls appear only below the element:
     * "next" scrolls down to the following nugget, "previous" back up. They
     * are plain anchor links, so they work without JS; the AMD module only
     * smooths the scrolling.
     *
     * @param \stdClass $nugget
     * @return string
     */
    private function render_neighbour_nav(\stdClass $nugget): string {
        $prev = (string)($nugget->prevanchor ?? '');
        $next = (string)($nugget->nextanchor ?? '');
        if ($prev === '' && $next === '') {
            return '';
        }

        $links = '';
        if ($prev !== '') {
            $links .= \html_writer::link('#' . $prev,
                \html_writer::span('↑', 'format-selfstudy-nuggets-navarrow', ['aria-hidden' => 'true']) .
                \html_writer::span(get_string('nuggetsexperience_prevnugget', 'format_selfstudy')),
                ['class' => 'format-selfstudy-nuggets-navlink', 'data-nugget-nav' => $prev]);
        }
        if ($next !== '') {
            $links .= \html_writer::link('#' . $next,
                \html_writer::span(get_string('nuggetsexperience_nextnugget', 'format_selfstudy')) .
                \html_writer::span('↓', 'format-selfstudy-nuggets-navarrow', ['aria-hidden' => 'true']),
                ['class' => 'format-selfstudy-nuggets-navlink format-selfstudy-nuggets-navlink-next',
                    'data-nugget-nav' => $next]);
        }

        return \html_writer::div($links, 'format-selfstudy-nuggets-neighbournav');
    }

    /**
     * Renders the "mark as done" toggle using Moodle's own manual completion.
     *
     * This is a plain post to core's togglecompletion.php, so completion truth
     * stays entirely inside Moodle; the feed only reflects it after reload.
     *
     * @param \stdClass $course
     * @param \stdClass $nugget
     * @return string
     */
    private function render_manual_completion_form(\stdClass $course, \stdClass $nugget): string {
        $backto = new \moodle_url('/course/view.php', ['id' => (int)$course->id]);
        $inputs =
            \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => (int)$nugget->cmid]) .
            \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'completionstate',
                'value' => $nugget->iscomplete ? 0 : 1]) .
            \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
            \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'backto',
                'value' => $backto->out(false) . '#module-' . (int)$nugget->cmid]) .
            \html_writer::empty_tag('input', [
                'type' => 'submit',
                'class' => 'btn btn-outline-secondary format-selfstudy-nuggets-completiontoggle',
                'value' => get_string($nugget->iscomplete ?
                    'nuggetsexperience_markundone' : 'nuggetsexperience_markdone', 'format_selfstudy'),
            ]);

        return \html_writer::tag('form', $inputs, [
            'method' => 'post',
            'action' => (new \moodle_url('/course/togglecompletion.php'))->out(false),
            'class' => 'format-selfstudy-nuggets-completionform',
        ]);
    }

    /**
     * Renders the end-of-feed marker or the completion summary.
     *
     * @param \stdClass $model
     * @return string
     */
    private function render_feed_end(\stdClass $model): string {
        if ($model->iscomplete) {
            $summary = \html_writer::tag('h3', get_string('nuggetsexperience_finishtitle', 'format_selfstudy')) .
                \html_writer::tag('p', get_string('nuggetsexperience_finishsummary', 'format_selfstudy', (object)[
                    'count' => count($model->completedtitles),
                ]));
            if ($model->completedtitles) {
                $items = '';
                foreach ($model->completedtitles as $title) {
                    $items .= \html_writer::tag('li', \s($title));
                }
                $summary .= \html_writer::tag('ul', $items, ['class' => 'format-selfstudy-nuggets-finishlist']);
            }
            return \html_writer::div($summary, 'format-selfstudy-nuggets-finish');
        }

        $note = get_string('nuggetsexperience_feedend', 'format_selfstudy',
            max(0, (int)$model->mandatorytotal - (int)$model->mandatorycomplete));
        if ($model->recommendednext !== null) {
            $note .= ' ' . \html_writer::link('#module-' . (int)$model->recommendednext->cmid,
                get_string('nuggetsexperience_next', 'format_selfstudy', \s($model->recommendednext->title)),
                ['class' => 'format-selfstudy-nuggets-nextlink']);
        }

        return \html_writer::div($note, 'format-selfstudy-nuggets-feedendnote');
    }

    /**
     * Loads the feed enhancement script (auto-scroll, scroll spy, live refresh).
     *
     * @param \stdClass $course
     * @param \stdClass $model
     */
    private function require_feed_script(\stdClass $course, \stdClass $model): void {
        global $PAGE;

        try {
            // On the very first visit the start hero must stay visible, so
            // auto-scroll only kicks in once there is progress to return to.
            $started = ((int)$model->mandatorycomplete + (int)$model->optionalcomplete) > 0;
            $PAGE->requires->js_call_amd('format_selfstudy/nuggets', 'init', [[
                'autoScroll' => (bool)$model->autoscroll && $started,
                'currentAnchor' => (string)$model->currentanchor,
                'scrollCompletion' => (bool)$model->scrollcompletion,
                'courseId' => (int)$course->id,
                'sesskey' => sesskey(),
                'ajaxUrl' => (new \moodle_url('/course/format/selfstudy/nuggets_ajax.php'))->out(false),
            ]]);
        } catch (\Throwable $exception) {
            // The feed works without JS; never break rendering over it.
            return;
        }
    }

    /**
     * Returns the label for a nugget status chip.
     *
     * @param \stdClass $nugget
     * @return string
     */
    private function status_label(\stdClass $nugget): string {
        if ($nugget->ismissing) {
            return get_string('nuggetsexperience_statusmissing', 'format_selfstudy');
        }
        if ($nugget->islocked) {
            return get_string('nuggetsexperience_statuslocked', 'format_selfstudy');
        }
        if ($nugget->iscomplete) {
            return get_string('nuggetsexperience_statuscomplete', 'format_selfstudy');
        }
        if ($nugget->status === 'started') {
            return get_string('nuggetsexperience_statusstarted', 'format_selfstudy');
        }
        return get_string('nuggetsexperience_statusopen', 'format_selfstudy');
    }

    /**
     * Formats a duration, falling back to plain minutes outside the format lib.
     *
     * @param int $minutes
     * @return string
     */
    private function format_duration(int $minutes): string {
        if (function_exists('format_selfstudy_format_duration')) {
            return \format_selfstudy_format_duration($minutes);
        }
        return $minutes . ' min';
    }
}
