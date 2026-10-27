<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade steps for format_selfstudy.
 *
 * The learning path tables moved to local_selfstudy, so the steps that used to
 * build them up here are gone. The old format_selfstudy_* tables are left in
 * place on purpose rather than dropped automatically - dropping them would
 * throw away course data. See docs/SPLIT_UEBERGABE_AN_FORMATE.md for the manual
 * cleanup.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_format_selfstudy_upgrade(int $oldversion): bool {
    global $DB;

    if ($oldversion < 2026102700) {
        // Kontakte geloeschter Kurse blieben bisher liegen.
        $DB->execute("DELETE FROM {format_selfstudy_contacts}
                       WHERE NOT EXISTS (SELECT 1 FROM {course} c WHERE c.id = courseid)");
        upgrade_plugin_savepoint(true, 2026102700, 'format', 'selfstudy');
    }

    return true;
}
