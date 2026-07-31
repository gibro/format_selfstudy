<?php
// This file is part of Moodle - http://moodle.org/

namespace selfstudyexperience_nuggets;

defined('MOODLE_INTERNAL') || die();

/**
 * Renders the full-screen Lern-Nuggets app (home overview + card player).
 *
 * Consumes the model derived by {@see card_builder} from the real course
 * content (section = nugget, activity = card). Markup follows the IG Metall
 * design handoff: home overview with streak and topic tiles, and a player of
 * typed full-screen cards driven by the format_selfstudy/nuggets_app AMD
 * module. Corporate design: no rounded corners, uppercase condensed headlines.
 */
class app_renderer {

    /** @var string base url of the plugin pix folder */
    private $pix;

    public function __construct() {
        $this->pix = (new \moodle_url('/course/format/selfstudy/pix/nuggets'))->out(false);
    }

    /**
     * Renders the whole app.
     *
     * @param \stdClass $home
     * @param array $nuggets nugget models keyed by section id
     * @param \moodle_url $backurl course view url for the close button
     * @return string
     */
    public function render(\stdClass $home, array $nuggets, \moodle_url $backurl): string {
        $players = '';
        foreach ($nuggets as $nugget) {
            $players .= $this->render_player($nugget);
        }

        return \html_writer::div(
            $this->render_home($home) . $players,
            'nq-app',
            ['data-navmodel' => 'snap', 'data-backurl' => $backurl->out(false)]
        );
    }

    /**
     * Renders the home / topic overview screen.
     *
     * @param \stdClass $home
     * @return string
     */
    private function render_home(\stdClass $home): string {
        $header = \html_writer::div(
            \html_writer::span('LERN-NUGGETS', 'nq-wordmark') .
            \html_writer::empty_tag('img', ['src' => $this->pix . '/igmetall-logo.svg', 'alt' => 'IG Metall',
                'class' => 'nq-logo']),
            'nq-home-header'
        );

        if (empty($home->topics)) {
            $body = \html_writer::div(
                \html_writer::tag('h1', \s($home->greeting), ['class' => 'nq-greeting']) .
                \html_writer::tag('p', get_string('nuggetsapp_empty', 'format_selfstudy'), ['class' => 'nq-subline']),
                'nq-home-scroll'
            );
            return \html_writer::tag('section', $header . $body . $this->render_tabbar($home->tabs),
                ['class' => 'nq-home', 'data-screen' => 'home']);
        }

        $topics = '';
        foreach ($home->topics as $topic) {
            $topics .= $this->render_topic($topic);
        }

        $scroll = \html_writer::div(
            \html_writer::tag('h1', \s($home->greeting), ['class' => 'nq-greeting']) .
            \html_writer::tag('p', \s($home->subline), ['class' => 'nq-subline']) .
            $this->render_streak($home->streak) .
            $this->section_title(get_string('nuggetsapp_today', 'format_selfstudy')) .
            ($home->hero ? $this->render_hero($home->hero) : '') .
            $this->section_title(get_string('nuggetsapp_topics', 'format_selfstudy')) .
            \html_writer::div($topics, 'nq-topics'),
            'nq-home-scroll'
        );

        return \html_writer::tag('section',
            $header . $scroll . $this->render_tabbar($home->tabs),
            ['class' => 'nq-home', 'data-screen' => 'home']);
    }

    /**
     * Renders the streak band.
     *
     * @param \stdClass $streak
     * @return string
     */
    private function render_streak(\stdClass $streak): string {
        $bars = '';
        for ($i = 0; $i < (int)$streak->week; $i++) {
            $bars .= \html_writer::span('', 'nq-streak-bar' . ($i < (int)$streak->days ? ' nq-streak-bar-on' : ''));
        }

        $row1 = \html_writer::div(
            $this->icon('Faust', 'nq-streak-icon') .
            \html_writer::span(\s($streak->days) . ' TAGE AM STÜCK', 'nq-streak-label') .
            \html_writer::span(\s($streak->done) . ' Nuggets', 'nq-streak-count'),
            'nq-streak-row1'
        );

        return \html_writer::div($row1 . \html_writer::div($bars, 'nq-streak-week'), 'nq-streak');
    }

    /**
     * Renders a section title with its 4px accent rule.
     *
     * @param string $text
     * @return string
     */
    private function section_title(string $text): string {
        return \html_writer::div(
            \html_writer::span(\s($text), 'nq-section-label') . \html_writer::span('', 'nq-section-rule'),
            'nq-section-title'
        );
    }

    /**
     * Renders the "Heute für dich" hero tile.
     *
     * @param \stdClass $hero
     * @return string
     */
    private function render_hero(\stdClass $hero): string {
        $mediainner = \html_writer::span('', 'nq-hero-triangle');
        if (!empty($hero->badge)) {
            $mediainner = \html_writer::span(\s($hero->badge), 'nq-badge nq-badge-red nq-hero-badge') . $mediainner;
        }
        $mediaattrs = ['class' => 'nq-hero-media' . ($hero->image ? '' : ' nq-hero-media-fallback')];
        if ($hero->image) {
            $mediaattrs['style'] = 'background-image: url(' . $hero->image . ')';
        }
        $media = \html_writer::div($mediainner, $mediaattrs['class'],
            isset($mediaattrs['style']) ? ['style' => $mediaattrs['style']] : []);

        $body = \html_writer::div(
            \html_writer::tag('h2', \s($hero->title), ['class' => 'nq-hero-title']) .
            \html_writer::tag('p', \s($hero->meta), ['class' => 'nq-hero-meta']) .
            \html_writer::tag('button', get_string('nuggetsapp_start', 'format_selfstudy'),
                ['class' => 'nq-btn nq-btn-red', 'type' => 'button', 'data-start' => $hero->nugget]),
            'nq-hero-body'
        );

        return \html_writer::tag('article', $media . $body, ['class' => 'nq-hero']);
    }

    /**
     * Renders one topic tile.
     *
     * @param \stdClass $topic
     * @return string
     */
    private function render_topic(\stdClass $topic): string {
        $main = \html_writer::div(
            \html_writer::span(\s($topic->title), 'nq-topic-title') .
            \html_writer::span(\s($topic->status), 'nq-topic-status') .
            \html_writer::div(
                \html_writer::span('', 'nq-topic-fill', ['style' => 'width: ' . (int)$topic->percent . '%']),
                'nq-topic-track'
            ),
            'nq-topic-main'
        );

        return \html_writer::tag('button',
            \html_writer::span($this->icon($topic->icon, 'nq-topic-glyph'), 'nq-topic-icon') . $main,
            ['class' => 'nq-topic', 'type' => 'button', 'data-start' => $topic->nugget]);
    }

    /**
     * Renders the fixed bottom tab bar.
     *
     * @param array $tabs
     * @return string
     */
    private function render_tabbar(array $tabs): string {
        $items = '';
        foreach ($tabs as $tab) {
            $items .= \html_writer::tag('button',
                $this->icon($tab->icon, 'nq-tab-glyph') . \html_writer::span(\s($tab->label), 'nq-tab-label'),
                ['class' => 'nq-tab' . (!empty($tab->active) ? ' nq-tab-active' : ''), 'type' => 'button']);
        }
        return \html_writer::tag('nav', $items, ['class' => 'nq-tabbar']);
    }

    /**
     * Renders one nugget player (chrome + cards + tap zones).
     *
     * @param \stdClass $nugget
     * @return string
     */
    private function render_player(\stdClass $nugget): string {
        $count = count($nugget->cards);

        $segments = '';
        for ($i = 0; $i < $count; $i++) {
            $segments .= \html_writer::span('', 'nq-seg');
        }

        $chrome = \html_writer::div(
            \html_writer::div(
                \html_writer::tag('button', '×',
                    ['class' => 'nq-close', 'type' => 'button', 'aria-label' => 'Schließen']) .
                \html_writer::span(\s($nugget->title), 'nq-chrome-title') .
                \html_writer::span('1 / ' . $count, 'nq-chrome-count'),
                'nq-chrome-row1'
            ) .
            \html_writer::div($segments, 'nq-chrome-segments'),
            'nq-chrome'
        );

        $cards = '';
        foreach ($nugget->cards as $index => $card) {
            $cards .= $this->render_card($card, $index, $count);
        }

        $tapzones = \html_writer::div('', 'nq-tap nq-tap-prev', ['data-tap' => 'prev']) .
            \html_writer::div('', 'nq-tap nq-tap-next', ['data-tap' => 'next']);

        return \html_writer::tag('section',
            $chrome . \html_writer::div($cards, 'nq-cards', ['data-cards' => 1]) . $tapzones,
            ['class' => 'nq-player', 'data-screen' => 'nugget', 'data-nugget' => $nugget->key, 'hidden' => 'hidden']);
    }

    /**
     * Dispatches to the card renderer for a type.
     *
     * @param \stdClass $card
     * @param int $index
     * @param int $count
     * @return string
     */
    private function render_card(\stdClass $card, int $index, int $count): string {
        switch ($card->type) {
            case 'cover':
                $inner = $this->card_cover($card);
                break;
            case 'video':
                $inner = $this->card_video($card);
                break;
            case 'interactive':
                $inner = $this->card_interactive($card);
                break;
            case 'memo':
                $inner = $this->card_memo($card);
                break;
            case 'outro':
                $inner = $this->card_outro($card);
                break;
            case 'figure':
            case 'steps':
            case 'statement':
            default:
                $inner = $this->card_content($card);
                break;
        }

        $attrs = [
            'class' => 'nq-card nq-card-' . $card->type . ' nq-bg-' . $card->bg,
            'data-index' => $index,
            'data-dark' => !empty($card->dark) ? 1 : 0,
        ];
        if (!empty($card->cmid)) {
            $attrs['id'] = 'module-' . (int)$card->cmid;
        }

        return \html_writer::tag('article', $inner, $attrs);
    }

    /**
     * Cover card (generated from the section).
     */
    private function card_cover(\stdClass $card): string {
        $logo = !empty($card->logo) ?
            \html_writer::empty_tag('img', ['src' => $this->pix . '/igmetall-logo.svg', 'alt' => 'IG Metall',
                'class' => 'nq-card-logo']) : '';

        $inner = \html_writer::div(
            \html_writer::span(\s($card->overline), 'nq-overline') .
            \html_writer::tag('h2', \s($card->title), ['class' => 'nq-card-h nq-cover-h']) .
            ($card->body !== '' ? \html_writer::tag('p', \s($card->body), ['class' => 'nq-card-body']) : ''),
            'nq-card-inner nq-cover-inner'
        );

        $hint = \html_writer::div(
            \html_writer::span('︿', 'nq-swipe-chevron', ['aria-hidden' => 'true']) .
            \html_writer::span('NACH OBEN WISCHEN', 'nq-swipe-text'),
            'nq-swipe-hint'
        );

        return $logo . \html_writer::span('', 'nq-cover-triangle') . $inner . $hint;
    }

    /**
     * Content card (statement/figure/steps): activity HTML in a typed wrapper.
     */
    private function card_content(\stdClass $card): string {
        $parts = '';
        if (!empty($card->overline)) {
            $parts .= \html_writer::span(\s($card->overline), 'nq-overline');
        }

        if (empty($card->html)) {
            // Link-only fallback: no inline content available.
            $parts .= \html_writer::tag('h2', \s($card->title), ['class' => 'nq-card-h nq-statement-h']);
            $parts .= $this->activity_link($card, true);
            return \html_writer::div($parts, 'nq-card-inner');
        }

        $parts .= \html_writer::div($card->html, 'nq-content');
        $parts .= $this->activity_link($card, false);
        return \html_writer::div($parts, 'nq-card-inner');
    }

    /**
     * Memo card: activity HTML in a grey box + a bookmark toggle.
     */
    private function card_memo(\stdClass $card): string {
        $overline = !empty($card->overline) ? \html_writer::span(\s($card->overline), 'nq-overline') : '';
        $box = !empty($card->html) ? \html_writer::div($card->html, 'nq-content') : '';

        return \html_writer::div(
            $overline .
            \html_writer::div($box, 'nq-memo-box') .
            \html_writer::tag('button', 'MERKEN',
                ['class' => 'nq-btn nq-btn-outline nq-memo-toggle', 'type' => 'button', 'data-memo' => 1]),
            'nq-card-inner'
        );
    }

    /**
     * Video card.
     */
    private function card_video(\stdClass $card): string {
        $glyph = \html_writer::span('', 'nq-icon nq-play-glyph',
            ['style' => '--nq-mask: url(' . $this->pix . '/icons/Play.svg)', 'aria-hidden' => 'true']);
        $play = $card->url ?
            \html_writer::link($card->url, $glyph, ['class' => 'nq-play', 'aria-label' => 'Video öffnen']) :
            \html_writer::tag('button', $glyph, ['class' => 'nq-play', 'type' => 'button', 'data-play' => 1]);

        $inner = \html_writer::div(
            \html_writer::span(\s($card->badge), 'nq-badge nq-badge-yellow') .
            \html_writer::tag('h2', \s($card->title), ['class' => 'nq-card-h']) .
            (!empty($card->body) ? \html_writer::tag('p', \s($card->body), ['class' => 'nq-card-body']) : ''),
            'nq-card-inner nq-video-inner'
        );

        $bg = $card->image ?
            \html_writer::span('', 'nq-video-bg', ['style' => 'background-image: url(' . $card->image . ')']) : '';

        return $bg . $play . $inner;
    }

    /**
     * Interactive card: embedded H5P (or other) activity content.
     */
    private function card_interactive(\stdClass $card): string {
        return \html_writer::div(
            \html_writer::span(\s($card->badge), 'nq-badge nq-badge-yellow') .
            \html_writer::tag('h2', \s($card->title), ['class' => 'nq-card-h nq-interactive-h']) .
            \html_writer::div($card->html, 'nq-content nq-interactive-content') .
            $this->activity_link($card, false),
            'nq-card-inner nq-interactive-inner'
        );
    }

    /**
     * Outro card (generated).
     */
    private function card_outro(\stdClass $card): string {
        $buttons = '';
        foreach ($card->buttons as $button) {
            $style = $button->style === 'solid-dark' ? 'nq-btn-dark' : 'nq-btn-outline-light';
            $buttons .= \html_writer::tag('button', \s($button->label),
                ['class' => 'nq-btn ' . $style, 'type' => 'button', 'data-finish' => 1]);
        }

        $streak = \html_writer::div(
            $this->icon('Faust', 'nq-outro-streak-icon') .
            \html_writer::span('0 Tage am Stück', 'nq-outro-streak-text'),
            'nq-outro-streak'
        );

        return \html_writer::div(
            \html_writer::tag('h2', \s($card->title), ['class' => 'nq-card-h']) .
            $streak .
            \html_writer::div($buttons, 'nq-outro-buttons'),
            'nq-card-inner'
        );
    }

    /**
     * Renders a link to the underlying activity.
     *
     * @param \stdClass $card
     * @param bool $primary render as a primary button (link-only cards)
     * @return string
     */
    private function activity_link(\stdClass $card, bool $primary): string {
        if (empty($card->url)) {
            return '';
        }
        $label = get_string('nuggetsapp_openactivity', 'format_selfstudy');
        if ($primary) {
            return \html_writer::div(
                \html_writer::link($card->url, $label, ['class' => 'nq-btn nq-btn-outline']),
                'nq-card-actions');
        }
        return \html_writer::link($card->url, $label, ['class' => 'nq-activity-link']);
    }

    /**
     * Returns a masked brand icon span.
     *
     * @param string $name
     * @param string $extraclass
     * @return string
     */
    private function icon(string $name, string $extraclass = ''): string {
        $url = $this->pix . '/icons/' . $name . '.svg';
        return \html_writer::span('', trim('nq-icon ' . $extraclass),
            ['style' => '--nq-mask: url(' . $url . ')', 'aria-hidden' => 'true']);
    }
}
