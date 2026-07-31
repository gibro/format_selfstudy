<?php
// This file is part of Moodle - http://moodle.org/

namespace selfstudyexperience_nuggets;

use format_selfstudy\local\path_repository;

defined('MOODLE_INTERNAL') || die();

if (!class_exists(__NAMESPACE__ . '\\inline_content')) {
    require_once(__DIR__ . '/inline_content.php');
}

/**
 * Builds the nugget model from the learner's base view.
 *
 * The model is derived, never stored: nuggets are the stations of the
 * published learning path, milestones become chapter separators and nugget
 * locks mirror Moodle availability. No learning logic is duplicated here;
 * this only re-reads the outline the base view already computed. The model
 * is shared by all display modes of the nuggets experience.
 */
class feed_builder {

    /** @var bool whether embeddable activities render inline in the feed */
    private $inlineembed = true;

    /** @var array pathkind cache per section id */
    private $sectionkinds = [];

    /** @var array section image url cache per section id */
    private $sectionimages = [];

    /**
     * Builds the nugget feed model for one learner.
     *
     * @param \stdClass $course
     * @param \stdClass $baseview
     * @param \stdClass $config
     * @return \stdClass
     */
    public function build(\stdClass $course, \stdClass $baseview, \stdClass $config): \stdClass {
        $outline = is_array($baseview->outline ?? null) ? $baseview->outline : [];
        $modinfo = $this->get_modinfo($course);

        $lockedvisibility = (string)($config->lockedvisibility ?? 'marked');
        if (!in_array($lockedvisibility, ['marked', 'reduced', 'hidden'], true)) {
            $lockedvisibility = 'marked';
        }
        $this->inlineembed = !isset($config->inlineembed) || !empty($config->inlineembed);

        $entries = $this->build_entries($course, $outline, $modinfo);
        $nuggets = array_values(array_filter($entries, static function(\stdClass $entry): bool {
            return $entry->kind === 'nugget';
        }));

        // Aggregate over every nugget before locked ones are hidden, so the
        // ratio never jumps when the teacher changes the visibility setting.
        $mandatorytotal = 0;
        $mandatorycomplete = 0;
        $optionaltotal = 0;
        $optionalcomplete = 0;
        $remainingminutes = 0;
        $totalminutes = 0;
        $seen = [];
        $seentotal = [];
        $segments = [];
        foreach ($nuggets as $nugget) {
            if ($nugget->ismissing) {
                continue;
            }
            $segments[] = (object)[
                'status' => $nugget->iscomplete ? 'complete' : ($nugget->islocked ? 'locked' : 'open'),
                'optional' => (bool)$nugget->optional,
                'title' => $nugget->title,
            ];
            if ($nugget->cmid && !isset($seentotal[$nugget->cmid])) {
                $seentotal[$nugget->cmid] = true;
                $totalminutes += max(0, (int)$nugget->durationminutes);
            }
            if ($nugget->optional) {
                $optionaltotal++;
                $optionalcomplete += $nugget->iscomplete ? 1 : 0;
                continue;
            }
            $mandatorytotal++;
            if ($nugget->iscomplete) {
                $mandatorycomplete++;
                continue;
            }
            if ($nugget->cmid && !isset($seen[$nugget->cmid])) {
                $seen[$nugget->cmid] = true;
                $remainingminutes += max(0, (int)$nugget->durationminutes);
            }
        }

        $current = $this->resolve_current($nuggets);
        if ($current !== null) {
            $current->iscurrent = true;
        }
        $recommendednext = $this->resolve_recommended_next($nuggets, $current);

        $entries = $this->apply_locked_visibility($entries, $lockedvisibility);
        $this->link_neighbours($entries);

        $completedtitles = [];
        foreach ($nuggets as $nugget) {
            if ($nugget->iscomplete) {
                $completedtitles[] = $nugget->title;
            }
        }

        return (object)[
            'mode' => 'feed',
            'lockedvisibility' => $lockedvisibility,
            'manualcompletionallowed' => !isset($config->manualcompletion) || !empty($config->manualcompletion),
            'autoscroll' => !isset($config->autoscroll) || !empty($config->autoscroll),
            'inlineembed' => $this->inlineembed,
            'scrollcompletion' => !isset($config->scrollcompletion) || !empty($config->scrollcompletion),
            'focusmode' => !isset($config->focusmode) || !empty($config->focusmode),
            'introtext' => trim((string)($config->introtext ?? '')),
            'totalminutes' => $totalminutes,
            'segments' => $segments,
            'entries' => $entries,
            'nuggetcount' => count($nuggets),
            'mandatorytotal' => $mandatorytotal,
            'mandatorycomplete' => $mandatorycomplete,
            'optionaltotal' => $optionaltotal,
            'optionalcomplete' => $optionalcomplete,
            'percentage' => $mandatorytotal > 0 ?
                (int)round($mandatorycomplete / $mandatorytotal * 100) : 0,
            'remainingminutes' => $remainingminutes,
            'iscomplete' => $mandatorytotal > 0 && $mandatorycomplete >= $mandatorytotal,
            'currentanchor' => $current !== null ? 'module-' . (int)$current->cmid : '',
            'recommendednext' => $recommendednext,
            'completedtitles' => $completedtitles,
        ];
    }

    /**
     * Maps the flat outline to feed entries (chapters and nuggets).
     *
     * @param \stdClass $course
     * @param \stdClass[] $outline
     * @param \course_modinfo|null $modinfo
     * @return \stdClass[]
     */
    private function build_entries(\stdClass $course, array $outline, ?\course_modinfo $modinfo): array {
        $entries = [];
        $chapternumber = 0;

        foreach ($outline as $entry) {
            $type = (string)($entry->type ?? '');
            if ($type === path_repository::ITEM_MILESTONE) {
                $chapternumber++;
                $entries[] = (object)[
                    'kind' => 'chapter',
                    'title' => trim((string)($entry->title ?? '')),
                    'number' => $chapternumber,
                ];
                continue;
            }
            if ($type !== path_repository::ITEM_STATION) {
                continue;
            }
            $entries[] = $this->build_nugget($course, $entry, $modinfo);
        }

        // Chapters without nuggets are headings that lead nowhere; drop them
        // like the escape view drops empty rooms.
        return $this->drop_empty_chapters($entries);
    }

    /**
     * Removes chapter separators that have no nuggets after them.
     *
     * @param \stdClass[] $entries
     * @return \stdClass[]
     */
    private function drop_empty_chapters(array $entries): array {
        $filtered = [];
        foreach ($entries as $entry) {
            if ($entry->kind === 'chapter') {
                $last = end($filtered);
                if ($last && $last->kind === 'chapter') {
                    array_pop($filtered);
                }
            }
            $filtered[] = $entry;
        }
        $last = end($filtered);
        if ($last && $last->kind === 'chapter') {
            array_pop($filtered);
        }

        return array_values($filtered);
    }

    /**
     * Maps one station outline entry to a nugget.
     *
     * @param \stdClass $course
     * @param \stdClass $entry
     * @param \course_modinfo|null $modinfo
     * @return \stdClass
     */
    private function build_nugget(\stdClass $course, \stdClass $entry, ?\course_modinfo $modinfo): \stdClass {
        $status = (string)($entry->status ?? 'open');
        $cmid = (int)($entry->cmid ?? 0);

        $nugget = (object)[
            'kind' => 'nugget',
            'title' => trim((string)($entry->title ?? '')),
            'status' => $status,
            'iscomplete' => $status === 'complete',
            'islocked' => $status === 'locked',
            'ismissing' => $status === 'missing',
            'iscurrent' => false,
            'url' => !empty($entry->url) ? (string)$entry->url : null,
            'availableinfo' => trim((string)($entry->availableinfo ?? '')),
            'cmid' => $cmid,
            'iconurl' => !empty($entry->iconurl) ? (string)$entry->iconurl : null,
            'iconalt' => trim((string)($entry->iconalt ?? '')),
            'modname' => '',
            'inlinecontent' => '',
            'embedhtml' => '',
            'embedtype' => '',
            'viewcompletion' => false,
            'description' => '',
            'imageurl' => null,
            'optional' => false,
            'manualcompletion' => false,
            'learninggoal' => '',
            'durationminutes' => 0,
            'prevanchor' => '',
            'nextanchor' => '',
        ];

        if ($cmid && function_exists('format_selfstudy_get_cm_metadata')) {
            $metadata = \format_selfstudy_get_cm_metadata($cmid);
            $nugget->learninggoal = trim((string)$metadata->learninggoal);
            $nugget->durationminutes = max(0, (int)$metadata->durationminutes);
        }

        if (!$cmid || !$modinfo) {
            return $nugget;
        }

        try {
            $cm = $modinfo->get_cm($cmid);
        } catch (\Throwable $exception) {
            return $nugget;
        }

        $nugget->modname = (string)$cm->modname;
        $nugget->manualcompletion = (int)$cm->completion === COMPLETION_TRACKING_MANUAL && $cm->uservisible;
        $nugget->viewcompletion = (int)$cm->completion === COMPLETION_TRACKING_AUTOMATIC &&
            (int)$cm->completionview === COMPLETION_VIEW_REQUIRED &&
            !$nugget->iscomplete && $cm->uservisible;

        try {
            $content = trim((string)$cm->get_formatted_content(['overflowdiv' => true, 'noclean' => true]));
        } catch (\Throwable $exception) {
            $content = '';
        }
        if ($cm->modname === 'label') {
            $nugget->inlinecontent = $content;
        } else {
            $nugget->description = $content;
        }

        // Embed suitable activities directly in the feed: the concept wants
        // learners to work top to bottom with as few subpage switches as
        // possible. Locked and missing nuggets never reveal their content.
        if ($this->inlineembed && !$nugget->islocked && !$nugget->ismissing) {
            $nugget->embedtype = (string)inline_content::embed_type($cm);
            if ($nugget->embedtype !== '') {
                $nugget->embedhtml = inline_content::build($cm);
                if ($nugget->embedhtml === '') {
                    $nugget->embedtype = '';
                }
            }
        }

        $sectionid = (int)$cm->section;
        $nugget->optional = $this->get_section_kind($course, $cm) === 'optional';
        $nugget->imageurl = $this->get_section_image($course, $sectionid);

        return $nugget;
    }

    /**
     * Returns the pathkind (required/optional) of a nugget's section.
     *
     * Optionality is a section decision (format option "pathkind"), so the
     * feed derives it instead of storing anything new.
     *
     * @param \stdClass $course
     * @param \cm_info $cm
     * @return string
     */
    private function get_section_kind(\stdClass $course, \cm_info $cm): string {
        $sectionid = (int)$cm->section;
        if (isset($this->sectionkinds[$sectionid])) {
            return $this->sectionkinds[$sectionid];
        }

        $kind = 'required';
        try {
            $section = $cm->get_section_info();
            if ($section) {
                $options = course_get_format($course)->get_format_options($section);
                $kind = (string)($options['pathkind'] ?? 'required') === 'optional' ? 'optional' : 'required';
            }
        } catch (\Throwable $exception) {
            $kind = 'required';
        }

        return $this->sectionkinds[$sectionid] = $kind;
    }

    /**
     * Returns the section overview image for a nugget, if any.
     *
     * @param \stdClass $course
     * @param int $sectionid
     * @return string|null
     */
    private function get_section_image(\stdClass $course, int $sectionid): ?string {
        if (array_key_exists($sectionid, $this->sectionimages)) {
            return $this->sectionimages[$sectionid];
        }

        $imageurl = null;
        if ($sectionid && function_exists('format_selfstudy_get_section_image_url')) {
            try {
                $url = \format_selfstudy_get_section_image_url($course, $sectionid);
                $imageurl = $url ? $url->out(false) : null;
            } catch (\Throwable $exception) {
                $imageurl = null;
            }
        }

        return $this->sectionimages[$sectionid] = $imageurl;
    }

    /**
     * Returns the nugget the learner should work on now.
     *
     * @param \stdClass[] $nuggets
     * @return \stdClass|null
     */
    private function resolve_current(array $nuggets): ?\stdClass {
        foreach ($nuggets as $nugget) {
            if ($nugget->iscomplete || $nugget->islocked || $nugget->ismissing) {
                continue;
            }
            if ($nugget->cmid && $nugget->url) {
                return $nugget;
            }
        }

        return null;
    }

    /**
     * Returns the first open mandatory nugget after the current one.
     *
     * @param \stdClass[] $nuggets
     * @param \stdClass|null $current
     * @return \stdClass|null
     */
    private function resolve_recommended_next(array $nuggets, ?\stdClass $current): ?\stdClass {
        if ($current === null) {
            return null;
        }

        $seencurrent = false;
        foreach ($nuggets as $nugget) {
            if ($nugget === $current) {
                $seencurrent = true;
                continue;
            }
            if (!$seencurrent) {
                continue;
            }
            if ($nugget->optional || $nugget->iscomplete || $nugget->islocked || $nugget->ismissing) {
                continue;
            }
            if ($nugget->cmid) {
                return $nugget;
            }
        }

        return null;
    }

    /**
     * Gives every visible nugget the anchors of its neighbours.
     *
     * The feed navigates by scrolling: "next" moves down to the following
     * visible nugget, "previous" moves back up. Hidden locked nuggets are
     * skipped because their anchors do not exist in the page.
     *
     * @param \stdClass[] $entries
     */
    private function link_neighbours(array $entries): void {
        $nuggets = [];
        foreach ($entries as $entry) {
            if ($entry->kind === 'nugget' && $entry->cmid) {
                $nuggets[] = $entry;
            }
        }

        foreach ($nuggets as $index => $nugget) {
            $nugget->prevanchor = $index > 0 ? 'module-' . (int)$nuggets[$index - 1]->cmid : '';
            $nugget->nextanchor = isset($nuggets[$index + 1]) ?
                'module-' . (int)$nuggets[$index + 1]->cmid : '';
        }
    }

    /**
     * Applies the locked-nugget visibility setting to the feed entries.
     *
     * Hidden mode also drops chapters whose nuggets all disappeared, so the
     * feed does not show headings that lead nowhere.
     *
     * @param \stdClass[] $entries
     * @param string $lockedvisibility
     * @return \stdClass[]
     */
    private function apply_locked_visibility(array $entries, string $lockedvisibility): array {
        if ($lockedvisibility === 'reduced') {
            foreach ($entries as $entry) {
                if ($entry->kind === 'nugget' && $entry->islocked) {
                    $entry->reduced = true;
                }
            }
            return $entries;
        }
        if ($lockedvisibility !== 'hidden') {
            return $entries;
        }

        $filtered = [];
        foreach ($entries as $entry) {
            if ($entry->kind === 'nugget' && $entry->islocked) {
                continue;
            }
            if ($entry->kind === 'chapter') {
                // Drop the previous chapter if it stayed empty.
                $last = end($filtered);
                if ($last && $last->kind === 'chapter') {
                    array_pop($filtered);
                }
            }
            $filtered[] = $entry;
        }
        $last = end($filtered);
        if ($last && $last->kind === 'chapter') {
            array_pop($filtered);
        }

        return array_values($filtered);
    }

    /**
     * Returns the course module info, or null when unavailable.
     *
     * @param \stdClass $course
     * @return \course_modinfo|null
     */
    private function get_modinfo(\stdClass $course): ?\course_modinfo {
        try {
            return get_fast_modinfo($course);
        } catch (\Throwable $exception) {
            return null;
        }
    }
}
