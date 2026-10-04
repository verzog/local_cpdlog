<?php
// Copyright (c) Skin Cancer College Australasia.
// All rights reserved.
//
// This file is part of a proprietary plugin developed by Skin Cancer
// College Australasia for use with Moodle. It is NOT free software and is
// NOT released under the GNU General Public License.
//
// Unauthorised copying, distribution, modification, or use of this file,
// in whole or in part, via any medium, is strictly prohibited without the
// prior written permission of Skin Cancer College Australasia. The software
// is provided "as is", without warranty of any kind, express or implied.
/**
 * Upgrade steps for the CPD logbook plugin.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
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

    return true;
}
