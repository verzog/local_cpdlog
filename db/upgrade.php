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

    if ($oldversion < 2026100900) {
        // The log of reminders sent before a reporting period closes.
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_cpdlog_reminder');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('periodid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('daysbefore', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timesent', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('periodid', XMLDB_KEY_FOREIGN, ['periodid'], 'local_cpdlog_period', ['id']);
        $table->add_index('userid-periodid-daysbefore', XMLDB_INDEX_UNIQUE, ['userid', 'periodid', 'daysbefore']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Existing reporting periods get their calendar events.
        \local_cpdlog\local\calendar::sync_all();
        upgrade_plugin_savepoint(true, 2026100900, 'local', 'cpdlog');
    }

    if ($oldversion < 2026101000) {
        // Categories may accept external activities, which have a name and provider instead of a course.
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_cpdlog_category');
        $field = new xmldb_field('allowexternal', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'evidencerequired');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('local_cpdlog_entry');
        foreach (['activityname' => 'coursename', 'provider' => 'activityname'] as $name => $after) {
            $field = new xmldb_field($name, XMLDB_TYPE_CHAR, '255', null, null, null, null, $after);
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026101000, 'local', 'cpdlog');
    }

    if ($oldversion < 2026101200) {
        // Staff decisions on course completions: released to the member's logbook, or excluded.
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_cpdlog_completion');
        $fields = [
            new xmldb_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null),
            new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null),
            new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null),
            new xmldb_field('entryid', XMLDB_TYPE_INTEGER, '10', null, null, null, null),
            new xmldb_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'released'),
            new xmldb_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('actionedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
        ];
        $index = new xmldb_index('courseid-status', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'status']);
        if (!$dbman->table_exists($table)) {
            foreach ($fields as $field) {
                $table->addField($field);
            }
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $table->add_index('userid-courseid', XMLDB_INDEX_UNIQUE, ['userid', 'courseid']);
            $table->addIndex($index);
            $dbman->create_table($table);
        } else {
            // A test site that ran an earlier build of this change has the table without these.
            $previous = 'entryid';
            foreach (array_slice($fields, 4) as $field) {
                $field->setPrevious($previous);
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
                $previous = $field->getName();
            }
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }

        // The course custom fields that say which courses award CPD.
        \local_cpdlog\local\course_cpd::setup_fields();
        upgrade_plugin_savepoint(true, 2026101200, 'local', 'cpdlog');
    }

    return true;
}
