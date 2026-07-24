<?php
// This file is part of Moodle - http://moodle.org/

namespace selfstudyexperience_escape;

use format_selfstudy\local\path_repository;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds the escape room model from the learner's base view.
 *
 * The model is derived, never stored: rooms are the path milestones, hotspots
 * are the stations inside them and room locks mirror Moodle availability. No
 * learning logic is duplicated here; this only re-reads the outline the base
 * view already computed.
 */
class room_builder {

    /**
     * Builds the escape model for one learner.
     *
     * @param \stdClass $course
     * @param \stdClass $baseview
     * @param \stdClass $config
     * @return \stdClass
     */
    public function build(\stdClass $course, \stdClass $baseview, \stdClass $config): \stdClass {
        $outline = is_array($baseview->outline ?? null) ? $baseview->outline : [];
        $rooms = $this->build_rooms($outline);

        $progress = $baseview->progress ?? (object)['complete' => 0, 'total' => 0, 'percentage' => 0];
        $keycount = 0;
        $hotspottotal = 0;
        foreach ($rooms as $room) {
            $keycount += $room->keycount;
            $hotspottotal += $room->hotspotcount;
        }

        $theme = (string)($config->theme ?? 'dungeon');
        if (!in_array($theme, ['dungeon', 'spaceship', 'mansion'], true)) {
            $theme = 'dungeon';
        }

        $complete = (int)($progress->complete ?? 0);
        $total = (int)($progress->total ?? 0);
        $iscomplete = $total > 0 && $complete >= $total;
        $starturl = trim((string)($progress->nexturl ?? ''));
        if ($starturl === '' && !$iscomplete) {
            // Only ever start inside the learning flow itself. If no station is
            // open, we deliberately show the lock hint instead of routing the
            // learner through unrelated material such as a general glossary.
            $starturl = $this->first_open_hotspot_url($rooms);
        }

        return (object)[
            'theme' => $theme,
            'introstory' => trim((string)($config->introstory ?? '')),
            'showinventory' => !isset($config->showinventory) || !empty($config->showinventory),
            'revealmystery' => !isset($config->revealmystery) || !empty($config->revealmystery),
            'progress' => (object)[
                'complete' => $complete,
                'total' => $total,
                'percentage' => max(0, min(100, (int)($progress->percentage ?? 0))),
            ],
            'started' => $complete > 0,
            'iscomplete' => $iscomplete,
            'starturl' => $starturl,
            'lockhint' => $starturl === '' && !$iscomplete ? $this->first_lock_hint($rooms) : '',
            'keycount' => $keycount,
            'hotspottotal' => $hotspottotal,
            'roomcount' => count($rooms),
            'rooms' => $rooms,
        ];
    }

    /**
     * Groups a flat outline into escape rooms.
     *
     * Each milestone opens a room; the stations that follow are its hotspots. A
     * path without milestones yields a single room holding every station.
     *
     * @param \stdClass[] $outline
     * @return \stdClass[]
     */
    private function build_rooms(array $outline): array {
        $rooms = [];
        $current = null;

        foreach ($outline as $entry) {
            $type = (string)($entry->type ?? '');
            if ($type === path_repository::ITEM_MILESTONE) {
                $current = $this->new_room((string)($entry->title ?? ''));
                $rooms[] = $current;
                continue;
            }
            if ($type !== path_repository::ITEM_STATION) {
                continue;
            }

            if ($current === null) {
                $current = $this->new_room('');
                $rooms[] = $current;
            }
            $current->hotspots[] = $this->build_hotspot($entry);
        }

        foreach ($rooms as $index => $room) {
            $this->finalise_room($room, $index);
        }

        // Drop trailing empty milestone rooms that never received a hotspot,
        // so the game does not show doors that lead nowhere.
        return array_values(array_filter($rooms, static function(\stdClass $room): bool {
            return !empty($room->hotspots);
        }));
    }

    /**
     * Creates an empty room record.
     *
     * @param string $title
     * @return \stdClass
     */
    private function new_room(string $title): \stdClass {
        return (object)[
            'title' => trim($title),
            'status' => 'open',
            'locked' => false,
            'iscomplete' => false,
            'keycount' => 0,
            'hotspotcount' => 0,
            'roomnumber' => 0,
            'hotspots' => [],
        ];
    }

    /**
     * Maps one station outline entry to a hotspot.
     *
     * @param \stdClass $entry
     * @return \stdClass
     */
    private function build_hotspot(\stdClass $entry): \stdClass {
        $status = (string)($entry->status ?? 'open');

        return (object)[
            'title' => trim((string)($entry->title ?? '')),
            'status' => $status,
            'iscomplete' => $status === 'complete',
            'islocked' => in_array($status, ['locked', 'missing'], true),
            'url' => !empty($entry->url) ? (string)$entry->url : null,
            'availableinfo' => trim((string)($entry->availableinfo ?? '')),
        ];
    }

    /**
     * Returns the URL of the first open (enterable) hotspot.
     *
     * @param \stdClass[] $rooms
     * @return string
     */
    private function first_open_hotspot_url(array $rooms): string {
        foreach ($rooms as $room) {
            foreach ($room->hotspots as $hotspot) {
                if (!$hotspot->iscomplete && !$hotspot->islocked && !empty($hotspot->url)) {
                    return (string)$hotspot->url;
                }
            }
        }

        return '';
    }

    /**
     * Returns the availability hint of the first locked hotspot, if any.
     *
     * When nothing is open yet, this explains what the learner has to do to
     * unlock the first room instead of leaving the game without a way in.
     *
     * @param \stdClass[] $rooms
     * @return string
     */
    private function first_lock_hint(array $rooms): string {
        foreach ($rooms as $room) {
            foreach ($room->hotspots as $hotspot) {
                if ($hotspot->islocked && $hotspot->availableinfo !== '') {
                    return $hotspot->availableinfo;
                }
            }
        }

        return '';
    }

    /**
     * Fills in the derived room state from its hotspots.
     *
     * @param \stdClass $room
     * @param int $index
     */
    private function finalise_room(\stdClass $room, int $index): void {
        $room->roomnumber = $index + 1;
        $room->hotspotcount = count($room->hotspots);
        $room->keycount = count(array_filter($room->hotspots, static function(\stdClass $hotspot): bool {
            return $hotspot->iscomplete;
        }));

        if (!$room->hotspots) {
            $room->status = 'open';
            return;
        }

        $lockedall = true;
        foreach ($room->hotspots as $hotspot) {
            if (!$hotspot->islocked) {
                $lockedall = false;
                break;
            }
        }

        if ($room->keycount >= $room->hotspotcount) {
            $room->status = 'complete';
            $room->iscomplete = true;
        } else if ($lockedall) {
            $room->status = 'locked';
            $room->locked = true;
        } else {
            $room->status = 'open';
        }
    }
}
