<?php
// This file is part of Moodle - http://moodle.org/

namespace selfstudyexperience_escape;

use format_selfstudy\local\experience_renderer_interface;

defined('MOODLE_INTERNAL') || die();

if (!class_exists(__NAMESPACE__ . '\\room_builder')) {
    require_once(__DIR__ . '/room_builder.php');
}

/**
 * Learner-facing renderer for the Escape selfstudy experience.
 */
class renderer implements experience_renderer_interface {

    /**
     * Returns whether the escape view can render for the current course.
     *
     * Without a learning path that has stations there are no rooms to stage, so
     * the core falls back to the base view instead of an empty game.
     *
     * @param \stdClass $course
     * @param \stdClass $baseview
     * @param \stdClass $config
     * @return bool
     */
    public function supports(\stdClass $course, \stdClass $baseview, \stdClass $config): bool {
        if (empty($baseview->path)) {
            return false;
        }
        $model = (new room_builder())->build($course, $baseview, $config);
        return !empty($model->rooms);
    }

    /**
     * Renders the learner-facing escape game entry.
     *
     * @param \stdClass $course
     * @param \stdClass $baseview
     * @param \stdClass $config
     * @return string
     */
    public function render_course_entry(\stdClass $course, \stdClass $baseview, \stdClass $config): string {
        $model = (new room_builder())->build($course, $baseview, $config);
        if (empty($model->rooms)) {
            return '';
        }

        $theme = clean_param((string)$model->theme, PARAM_ALPHA);

        $rooms = '';
        foreach ($model->rooms as $room) {
            $rooms .= $this->render_room($room, $model);
        }

        return \html_writer::tag('section',
            $this->render_intro($course, $model) .
            ($model->showinventory ? $this->render_inventory($model) : '') .
            \html_writer::div($rooms, 'format-selfstudy-escape-rooms'),
            ['class' => 'format-selfstudy-escape format-selfstudy-escape-theme-' . $theme]
        );
    }

    /**
     * The escape view carries its own room navigation, so no activity hint is added.
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
     * Renders the intro hero with story and progress.
     *
     * @param \stdClass $course
     * @param \stdClass $model
     * @return string
     */
    private function render_intro(\stdClass $course, \stdClass $model): string {
        $progress = $model->progress;
        $storytext = $model->introstory !== '' ? $model->introstory :
            get_string('escapeexperience_introdefault', 'format_selfstudy');

        $badge = \html_writer::div(
            \html_writer::span($this->theme_emoji($model->theme), 'format-selfstudy-escape-badgeicon',
                ['aria-hidden' => 'true']),
            'format-selfstudy-escape-badge'
        );

        $copy = \html_writer::span(get_string('escapeexperience_name', 'format_selfstudy'),
                'format-selfstudy-escape-eyebrow') .
            \html_writer::tag('h2', format_string($course->fullname), ['class' => 'format-selfstudy-escape-title']) .
            \html_writer::div(\s($storytext), 'format-selfstudy-escape-story') .
            $this->render_start_action($model) .
            \html_writer::div(
                \html_writer::div('', 'format-selfstudy-escape-progressfill',
                    ['style' => 'width: ' . (int)$progress->percentage . '%']),
                'format-selfstudy-escape-progressbar'
            ) .
            \html_writer::div(get_string('escapeexperience_progressnote', 'format_selfstudy', (object)[
                'complete' => (int)$progress->complete,
                'total' => (int)$progress->total,
                'percentage' => (int)$progress->percentage,
            ]), 'format-selfstudy-escape-progressnote');

        return \html_writer::div(
            $badge . \html_writer::div($copy, 'format-selfstudy-escape-introcopy'),
            'format-selfstudy-escape-intro'
        );
    }

    /**
     * Renders the primary start/continue action.
     *
     * @param \stdClass $model
     * @return string
     */
    private function render_start_action(\stdClass $model): string {
        if (!empty($model->iscomplete)) {
            return \html_writer::div(
                \html_writer::span('🏆', 'format-selfstudy-escape-solvedicon', ['aria-hidden' => 'true']) .
                \html_writer::span(get_string('escapeexperience_solved', 'format_selfstudy')),
                'format-selfstudy-escape-solved'
            );
        }

        if ($model->starturl !== '') {
            $label = !empty($model->started) ?
                get_string('escapeexperience_continue', 'format_selfstudy') :
                get_string('escapeexperience_start', 'format_selfstudy');
            return \html_writer::div(
                \html_writer::link($model->starturl, $label, [
                    'class' => 'btn btn-primary format-selfstudy-escape-startbutton',
                ]),
                'format-selfstudy-escape-actions'
            );
        }

        // Nothing is open yet: explain how to unlock the first room.
        $hint = $model->lockhint !== '' ? $model->lockhint :
            get_string('escapeexperience_lockeddefault', 'format_selfstudy');
        return \html_writer::div(
            \html_writer::span('🔒', 'format-selfstudy-escape-lockicon', ['aria-hidden' => 'true']) .
            \html_writer::span(\s($hint)),
            'format-selfstudy-escape-locknote'
        );
    }

    /**
     * Returns the decorative emoji for a theme.
     *
     * @param string $theme
     * @return string
     */
    private function theme_emoji(string $theme): string {
        $map = ['dungeon' => '🗝️', 'spaceship' => '🚀', 'mansion' => '🕯️'];
        return $map[$theme] ?? '🗝️';
    }

    /**
     * Renders the collected-keys inventory bar.
     *
     * @param \stdClass $model
     * @return string
     */
    private function render_inventory(\stdClass $model): string {
        $parts = [];
        if ((int)($model->itemtotal ?? 0) > 0) {
            $parts[] = \html_writer::span('📦', 'format-selfstudy-escape-inventoryicon', ['aria-hidden' => 'true']) .
                \html_writer::span(\s(get_string('escapeexperience_inventoryitems', 'format_selfstudy', (object)[
                    'items' => (int)$model->itemcount,
                    'total' => (int)$model->itemtotal,
                ])), 'format-selfstudy-escape-inventorylabel');
        }
        if ((int)($model->riddletotal ?? 0) > 0) {
            $parts[] = \html_writer::span('🧩', 'format-selfstudy-escape-inventoryicon', ['aria-hidden' => 'true']) .
                \html_writer::span(\s(get_string('escapeexperience_inventoryriddles', 'format_selfstudy', (object)[
                    'solved' => (int)$model->riddlesolved,
                    'total' => (int)$model->riddletotal,
                ])), 'format-selfstudy-escape-inventorylabel');
        }
        if (!$parts) {
            $parts[] = \html_writer::span('🔑', 'format-selfstudy-escape-inventoryicon', ['aria-hidden' => 'true']) .
                \html_writer::span(\s(get_string('escapeexperience_inventory', 'format_selfstudy', (object)[
                    'keys' => (int)$model->keycount,
                    'total' => (int)$model->hotspottotal,
                ])), 'format-selfstudy-escape-inventorylabel');
        }

        $output = '';
        foreach ($parts as $part) {
            $output .= \html_writer::div($part, 'format-selfstudy-escape-inventory');
        }

        return \html_writer::div($output, 'format-selfstudy-escape-inventoryrow');
    }

    /**
     * Renders one room card.
     *
     * @param \stdClass $room
     * @param \stdClass $model
     * @return string
     */
    private function render_room(\stdClass $room, \stdClass $model): string {
        $statuslabel = $this->room_status_label($room->status);
        $title = $room->title !== '' ? $room->title :
            get_string('escapeexperience_roomdefault', 'format_selfstudy', $room->roomnumber);

        $head = \html_writer::div(
            \html_writer::span(get_string('escapeexperience_roomnumber', 'format_selfstudy', $room->roomnumber),
                'format-selfstudy-escape-roomnumber') .
            \html_writer::span(\s($statuslabel), 'format-selfstudy-escape-roomstatus'),
            'format-selfstudy-escape-roommeta'
        ) . \html_writer::tag('h3', \s($title), ['class' => 'format-selfstudy-escape-roomtitle']);

        if ($room->status === 'locked' && !$model->revealmystery) {
            $body = \html_writer::div(get_string('escapeexperience_roomlockedmystery', 'format_selfstudy'),
                'format-selfstudy-escape-roomlocked');
        } else {
            $body = $this->render_hotspots($room);
        }

        return \html_writer::tag('article', $head . $body, [
            'class' => 'format-selfstudy-escape-room format-selfstudy-escape-room-' . $room->status,
        ]);
    }

    /**
     * Renders the hotspots of a room.
     *
     * @param \stdClass $room
     * @return string
     */
    private function render_hotspots(\stdClass $room): string {
        if (!$room->hotspots) {
            return '';
        }

        $items = '';
        foreach ($room->hotspots as $hotspot) {
            $roleicons = ['riddle' => '🧩', 'item' => '📦', 'key' => '•'];
            $openicon = $roleicons[(string)($hotspot->role ?? 'key')] ?? '•';
            $icon = $hotspot->iscomplete ? '✓' : ($hotspot->islocked ? '🔒' : $openicon);
            $label = \html_writer::span($icon, 'format-selfstudy-escape-hotspoticon', ['aria-hidden' => 'true']) .
                \html_writer::span(\s($hotspot->title), 'format-selfstudy-escape-hotspottitle');

            if (!$hotspot->islocked && $hotspot->url) {
                $content = \html_writer::link($hotspot->url, $label, [
                    'class' => 'format-selfstudy-escape-hotspotlink',
                ]);
            } else {
                $content = \html_writer::span($label, 'format-selfstudy-escape-hotspotplain');
            }

            if ($hotspot->islocked && $hotspot->availableinfo !== '') {
                $content .= \html_writer::div(\s($hotspot->availableinfo),
                    'format-selfstudy-escape-hotspothint');
            }

            $items .= \html_writer::tag('li', $content, [
                'class' => 'format-selfstudy-escape-hotspot format-selfstudy-escape-hotspot-' .
                    ($hotspot->iscomplete ? 'done' : ($hotspot->islocked ? 'locked' : 'open')),
            ]);
        }

        return \html_writer::tag('ul', $items, ['class' => 'format-selfstudy-escape-hotspots']);
    }

    /**
     * Returns the label for a room status.
     *
     * @param string $status
     * @return string
     */
    private function room_status_label(string $status): string {
        $keys = [
            'locked' => 'escapeexperience_roomstatuslocked',
            'complete' => 'escapeexperience_roomstatuscomplete',
            'open' => 'escapeexperience_roomstatusopen',
        ];
        $key = $keys[$status] ?? $keys['open'];
        return get_string($key, 'format_selfstudy');
    }
}
