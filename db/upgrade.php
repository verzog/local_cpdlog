<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Upgrade steps for the CPD logbook plugin.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrades the CPD logbook plugin.
 *
 * @param int $oldversion The version being upgraded from.
 * @return bool
 */
function xmldb_local_cpdlog_upgrade($oldversion) {
    global $DB;

    if ($oldversion < 2026092403) {
        // Sites installed before the starting categories existed get them now.
        \local_cpdlog\local\setup::add_default_categories();
        upgrade_plugin_savepoint(true, 2026092403, 'local', 'cpdlog');
    }

    if ($oldversion < 2026092405) {
        // Entries become persistents, which record who last changed each row.
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_cpdlog_entry');
        $field = new xmldb_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timemodified');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $key = new xmldb_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        $dbman->add_key($table, $key);
        upgrade_plugin_savepoint(true, 2026092405, 'local', 'cpdlog');
    }

    if ($oldversion < 2026092601) {
        // Sites installed before the CPD report sources existed get the starting reports now.
        \local_cpdlog\local\setup::add_default_reports();
        upgrade_plugin_savepoint(true, 2026092601, 'local', 'cpdlog');
    }

    if ($oldversion < 2026100400) {
        // The register of staff deletions of a member's CPD data.
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_cpdlog_deletion');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('requestedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'queued');
        $table->add_field('entriesdeleted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('filesdeleted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timerequested', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('requestedby', XMLDB_KEY_FOREIGN, ['requestedby'], 'user', ['id']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026100400, 'local', 'cpdlog');
    }

    if ($oldversion < 2026100403) {
        // Remember which image blog awards a deletion covered, so they are never copied back.
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_cpdlog_deletion');
        $field = new xmldb_field('imageblogawardid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timecompleted');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026100403, 'local', 'cpdlog');
    }

    return true;
}
