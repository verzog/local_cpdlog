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

    return true;
}
