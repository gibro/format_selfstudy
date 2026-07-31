<?php
// This file is part of Moodle - http://moodle.org/

namespace selfstudyexperience_nuggets;

defined('MOODLE_INTERNAL') || die();

if (!class_exists(__NAMESPACE__ . '\\inline_content')) {
    require_once(__DIR__ . '/inline_content.php');
}

/**
 * Derives the Lern-Nuggets app model from real Moodle course content.
 *
 * Mapping (confirmed with the product owner): a course section is a nugget,
 * each activity in it becomes a full-screen card. The card type is derived
 * from the activity type and can be overridden per activity (cmgoals field
 * `nuggetcardtype`). A cover card is generated from the section, an outro is
 * appended. No learning content is stored here — it is read from the
 * activities, mirroring the escape room's derivation from the outline.
 */
class card_builder {

    /** @var string[] icon palette cycled across sections without an own icon */
    private const ICON_PALETTE = ['Megafon', 'Rechtlich-Gesetz-Paragraf', 'Sicherheit-Schloss', 'Gruppe-mit-Fahnen'];

    /** Background + chrome darkness per card type. */
    private const TYPE_BG = [
        'cover' => ['bg' => 'sunrise', 'dark' => true],
        'statement' => ['bg' => 'light', 'dark' => false],
        'figure' => ['bg' => 'red', 'dark' => true],
        'steps' => ['bg' => 'light', 'dark' => false],
        'memo' => ['bg' => 'light', 'dark' => false],
        'video' => ['bg' => 'dark', 'dark' => true],
        'interactive' => ['bg' => 'light', 'dark' => false],
        'outro' => ['bg' => 'sunrise', 'dark' => true],
    ];

    /**
     * Builds the home overview model from the course sections.
     *
     * @param \stdClass $course
     * @return \stdClass
     */
    public function home(\stdClass $course): \stdClass {
        $sections = $this->nugget_sections($course);

        $topics = [];
        $hero = null;
        $index = 0;
        foreach ($sections as $section) {
            $progress = $this->section_progress($course, $section->cms);
            $duration = $this->section_duration($section->cms);
            $topic = (object)[
                'icon' => self::ICON_PALETTE[$index % count(self::ICON_PALETTE)],
                'title' => $section->title,
                'status' => $progress->total > 0 && $progress->done > 0 ?
                    get_string('nuggetsapp_progressof', 'format_selfstudy',
                        (object)['done' => $progress->done, 'total' => $progress->total]) :
                    get_string('nuggetsapp_new', 'format_selfstudy'),
                'percent' => $progress->percent,
                'nugget' => (string)$section->id,
            ];
            $topics[] = $topic;

            if ($hero === null) {
                $hero = (object)[
                    'badge' => $progress->done === 0 ? get_string('nuggetsapp_new', 'format_selfstudy') : null,
                    'image' => $section->image,
                    'title' => $section->title,
                    'meta' => get_string('nuggetsapp_cardsmeta', 'format_selfstudy', (object)[
                        'cards' => $progress->total,
                        'duration' => $this->format_duration($duration),
                    ]),
                    'nugget' => (string)$section->id,
                ];
            }
            $index++;
        }

        return (object)[
            'greeting' => get_string('nuggetsapp_greeting', 'format_selfstudy'),
            'subline' => get_string('nuggetsapp_subline', 'format_selfstudy'),
            'streak' => (object)['days' => 0, 'week' => 7, 'done' => 0],
            'hero' => $hero,
            'topics' => $topics,
            'tabs' => [
                (object)['icon' => 'Liste', 'label' => 'Feed', 'active' => true],
                (object)['icon' => 'Suchen-Lupe', 'label' => 'Themen', 'active' => false],
                (object)['icon' => 'Herz', 'label' => 'Gemerkt', 'active' => false],
                (object)['icon' => 'Person', 'label' => 'Profil', 'active' => false],
            ],
        ];
    }

    /**
     * Builds all nuggets (one per section) with their derived cards.
     *
     * @param \stdClass $course
     * @return array nugget models keyed by section id
     */
    public function nuggets(\stdClass $course): array {
        $nuggets = [];
        foreach ($this->nugget_sections($course) as $index => $section) {
            $cards = [$this->cover_card($section, $index)];
            foreach ($section->cms as $cm) {
                $card = $this->activity_card($course, $cm);
                if ($card !== null) {
                    $cards[] = $card;
                }
            }
            $cards[] = $this->outro_card();

            $nuggets[(string)$section->id] = (object)[
                'key' => (string)$section->id,
                'title' => \core_text::strtoupper($section->title),
                'cards' => $cards,
            ];
        }
        return $nuggets;
    }

    /**
     * Returns whether the course has any nugget to show.
     *
     * @param \stdClass $course
     * @return bool
     */
    public function has_content(\stdClass $course): bool {
        return !empty($this->nugget_sections($course));
    }

    /**
     * Collects the course sections that hold nugget activities.
     *
     * @param \stdClass $course
     * @return \stdClass[] each with id, title, summary, image, cms[]
     */
    private function nugget_sections(\stdClass $course): array {
        try {
            $modinfo = get_fast_modinfo($course);
        } catch (\Throwable $exception) {
            return [];
        }

        $format = course_get_format($course);
        $sections = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            if ((int)$section->section === 0 || !$section->uservisible) {
                continue;
            }
            $cms = [];
            foreach ((array)($modinfo->sections[$section->section] ?? []) as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if ($this->is_card_activity($cm)) {
                    $cms[] = $cm;
                }
            }
            if (!$cms) {
                continue;
            }

            $title = trim((string)$section->name) !== '' ? format_string($section->name) :
                get_section_name($course, $section);
            $image = null;
            if (function_exists('format_selfstudy_get_section_image_url')) {
                $url = \format_selfstudy_get_section_image_url($course, (int)$section->id);
                $image = $url ? $url->out(false) : null;
            }

            $sections[] = (object)[
                'id' => (int)$section->id,
                'number' => (int)$section->section,
                'title' => $title,
                'summary' => $this->section_summary($section),
                'image' => $image,
                'cms' => $cms,
            ];
        }
        return $sections;
    }

    /**
     * Returns whether a cm should become a card.
     *
     * @param \cm_info $cm
     * @return bool
     */
    private function is_card_activity(\cm_info $cm): bool {
        if (!$cm->uservisible || !empty($cm->deletioninprogress)) {
            return false;
        }
        return $cm->modname !== 'learningmap';
    }

    /**
     * Builds the generated cover card for a section.
     *
     * @param \stdClass $section
     * @param int $index
     * @return \stdClass
     */
    private function cover_card(\stdClass $section, int $index): \stdClass {
        return $this->with_bg((object)[
            'type' => 'cover',
            'logo' => true,
            'overline' => get_string('nuggetsapp_topics', 'format_selfstudy') . ' · ' . ($index + 1),
            'title' => \core_text::strtoupper($section->title),
            'body' => $section->summary,
            'image' => $section->image,
        ]);
    }

    /**
     * Builds the appended outro card.
     *
     * @return \stdClass
     */
    private function outro_card(): \stdClass {
        return $this->with_bg((object)[
            'type' => 'outro',
            'title' => get_string('nuggetsapp_outrotitle', 'format_selfstudy'),
            'buttons' => [
                (object)['label' => get_string('nuggetsapp_backtooverview', 'format_selfstudy'),
                    'style' => 'outline-light'],
            ],
        ]);
    }

    /**
     * Maps one activity to a typed card.
     *
     * @param \stdClass $course
     * @param \cm_info $cm
     * @return \stdClass|null
     */
    private function activity_card(\stdClass $course, \cm_info $cm): ?\stdClass {
        $metadata = \format_selfstudy_get_cm_metadata((int)$cm->id);
        $type = $this->resolve_type($cm, (string)$metadata->nuggetcardtype);
        $overline = trim((string)$metadata->learninggoal);
        $url = $cm->url ? $cm->url->out(false) : null;
        $name = format_string($cm->get_name());

        if ($type === 'interactive') {
            $html = inline_content::build($cm);
            if ($html === '') {
                return $this->link_card($cm, $name, $overline, $url);
            }
            return $this->with_bg((object)[
                'type' => 'interactive',
                'cmid' => (int)$cm->id,
                'url' => $url,
                'title' => $name,
                'badge' => get_string('nuggetsapp_interactive', 'format_selfstudy'),
                'html' => $html,
            ]);
        }

        if ($type === 'video') {
            return $this->with_bg((object)[
                'type' => 'video',
                'cmid' => (int)$cm->id,
                'url' => $url,
                'title' => $name,
                'body' => $overline,
                'image' => $this->cm_image($cm),
                'badge' => 'VIDEO',
            ]);
        }

        // Content cards (statement/figure/steps/memo): render the activity's
        // own HTML inside the typed visual wrapper.
        $html = $this->content_html($cm);
        if ($html === '') {
            return $this->link_card($cm, $name, $overline, $url);
        }
        return $this->with_bg((object)[
            'type' => $type,
            'cmid' => (int)$cm->id,
            'url' => $url,
            'title' => $name,
            'overline' => $overline,
            'html' => $html,
        ]);
    }

    /**
     * Resolves the card type: explicit override wins, otherwise auto by modname.
     *
     * @param \cm_info $cm
     * @param string $override
     * @return string
     */
    private function resolve_type(\cm_info $cm, string $override): string {
        $allowed = ['statement', 'figure', 'steps', 'video', 'memo'];
        if (in_array($override, $allowed, true)) {
            return $override;
        }

        switch ($cm->modname) {
            case 'h5pactivity':
                return 'interactive';
            case 'url':
            case 'resource':
                return 'video';
            case 'label':
            case 'page':
            default:
                return 'statement';
        }
    }

    /**
     * Returns the activity's renderable HTML content (page or label).
     *
     * @param \cm_info $cm
     * @return string
     */
    private function content_html(\cm_info $cm): string {
        global $DB, $CFG;

        try {
            if ($cm->modname === 'page') {
                require_once($CFG->libdir . '/filelib.php');
                $page = $DB->get_record('page', ['id' => (int)$cm->instance]);
                if (!$page) {
                    return '';
                }
                $context = \context_module::instance($cm->id);
                $content = file_rewrite_pluginfile_urls($page->content, 'pluginfile.php', $context->id,
                    'mod_page', 'content', $page->revision);
                return trim((string)format_text($content, $page->contentformat,
                    ['context' => $context, 'noclean' => true, 'overflowdiv' => false]));
            }
            if ($cm->modname === 'label') {
                return trim((string)$cm->get_formatted_content(['overflowdiv' => false, 'noclean' => true]));
            }
        } catch (\Throwable $exception) {
            return '';
        }
        return '';
    }

    /**
     * Fallback card that just links to the activity.
     *
     * @param \cm_info $cm
     * @param string $name
     * @param string $overline
     * @param string|null $url
     * @return \stdClass
     */
    private function link_card(\cm_info $cm, string $name, string $overline, ?string $url): \stdClass {
        return $this->with_bg((object)[
            'type' => 'statement',
            'cmid' => (int)$cm->id,
            'url' => $url,
            'title' => $name,
            'overline' => $overline,
            'html' => '',
            'linkonly' => true,
        ]);
    }

    /**
     * Returns a representative image URL for a cm, if any.
     *
     * @param \cm_info $cm
     * @return string|null
     */
    private function cm_image(\cm_info $cm): ?string {
        if (function_exists('format_selfstudy_get_section_image_url')) {
            $url = \format_selfstudy_get_section_image_url($cm->get_course(), (int)$cm->section);
            return $url ? $url->out(false) : null;
        }
        return null;
    }

    /**
     * Adds the background + darkness for the card type.
     *
     * @param \stdClass $card
     * @return \stdClass
     */
    private function with_bg(\stdClass $card): \stdClass {
        $meta = self::TYPE_BG[$card->type] ?? self::TYPE_BG['statement'];
        $card->bg = $meta['bg'];
        $card->dark = $meta['dark'];
        return $card;
    }

    /**
     * Returns the completion progress of a section's activities.
     *
     * @param \stdClass $course
     * @param \cm_info[] $cms
     * @return \stdClass done/total/percent
     */
    private function section_progress(\stdClass $course, array $cms): \stdClass {
        global $USER;

        $completion = new \completion_info($course);
        $total = 0;
        $done = 0;
        foreach ($cms as $cm) {
            if (!$completion->is_enabled($cm)) {
                continue;
            }
            $total++;
            $data = $completion->get_data($cm, false, (int)$USER->id);
            if (in_array((int)$data->completionstate, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true)) {
                $done++;
            }
        }
        // Without completion tracking, fall back to the card count as the total.
        if ($total === 0) {
            $total = count($cms);
        }

        return (object)[
            'done' => $done,
            'total' => $total,
            'percent' => $total > 0 ? (int)round($done / $total * 100) : 0,
        ];
    }

    /**
     * Sums the redactional duration of a section's activities.
     *
     * @param \cm_info[] $cms
     * @return int minutes
     */
    private function section_duration(array $cms): int {
        $minutes = 0;
        foreach ($cms as $cm) {
            $minutes += max(0, (int)\format_selfstudy_get_cm_metadata((int)$cm->id)->durationminutes);
        }
        return $minutes;
    }

    /**
     * Formats a duration, falling back outside the format lib (e.g. in tests).
     *
     * @param int $minutes
     * @return string
     */
    private function format_duration(int $minutes): string {
        if (function_exists('format_selfstudy_format_duration')) {
            return \format_selfstudy_format_duration($minutes);
        }
        if ($minutes <= 0) {
            return '–';
        }
        if ($minutes < 60) {
            return $minutes . ' Min.';
        }
        return round($minutes / 60, 1) . ' Std.';
    }

    /**
     * Returns the plain-text section summary for a cover card.
     *
     * @param \section_info $section
     * @return string
     */
    private function section_summary(\section_info $section): string {
        $summary = trim((string)($section->summary ?? ''));
        if ($summary === '') {
            return '';
        }
        return trim(html_to_text(format_text($summary, (int)($section->summaryformat ?? FORMAT_HTML),
            ['context' => \context_course::instance($section->course)]), 0, false));
    }
}
