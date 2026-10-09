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
 * Privacy provider for the CPD logbook plugin.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for the CPD logbook plugin.
 *
 * All data is held at system context. Exports cover a member's own entries, cohort choices and the
 * register of deletions of their data, and a staff member's own actions. Deletion is deliberately not
 * automatic: CPD entries are compliance records that SCCA retains, and staff delete them with the CPD
 * data deletion tool (see docs/decisions.md).
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider
{
    /** @var string[] User id columns in the entry table. */
    const ENTRY_USER_FIELDS = ['userid', 'reviewedby', 'reversedby', 'usermodified'];

    /** @var string[] Configuration tables that record the staff member who last changed a row. */
    const CONFIG_TABLES = ['local_cpdlog_category', 'local_cpdlog_period', 'local_cpdlog_target'];

    /**
     * Describes the personal data stored by the plugin.
     *
     * @param collection $collection The collection to add metadata to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_cpdlog_entry', [
            'userid' => 'privacy:metadata:local_cpdlog_entry:userid',
            'categoryid' => 'privacy:metadata:local_cpdlog_entry:categoryid',
            'periodid' => 'privacy:metadata:local_cpdlog_entry:periodid',
            'courseid' => 'privacy:metadata:local_cpdlog_entry:courseid',
            'coursename' => 'privacy:metadata:local_cpdlog_entry:coursename',
            'hours' => 'privacy:metadata:local_cpdlog_entry:hours',
            'activitydate' => 'privacy:metadata:local_cpdlog_entry:activitydate',
            'description' => 'privacy:metadata:local_cpdlog_entry:description',
            'status' => 'privacy:metadata:local_cpdlog_entry:status',
            'source' => 'privacy:metadata:local_cpdlog_entry:source',
            'externalref' => 'privacy:metadata:local_cpdlog_entry:externalref',
            'syncstatus' => 'privacy:metadata:local_cpdlog_entry:syncstatus',
            'timesubmitted' => 'privacy:metadata:local_cpdlog_entry:timesubmitted',
            'reviewedby' => 'privacy:metadata:local_cpdlog_entry:reviewedby',
            'timereviewed' => 'privacy:metadata:local_cpdlog_entry:timereviewed',
            'rejectionreason' => 'privacy:metadata:local_cpdlog_entry:rejectionreason',
            'reversedby' => 'privacy:metadata:local_cpdlog_entry:reversedby',
            'timereversed' => 'privacy:metadata:local_cpdlog_entry:timereversed',
            'reversalreason' => 'privacy:metadata:local_cpdlog_entry:reversalreason',
            'timecreated' => 'privacy:metadata:local_cpdlog_entry:timecreated',
            'timemodified' => 'privacy:metadata:local_cpdlog_entry:timemodified',
            'usermodified' => 'privacy:metadata:usermodified',
        ], 'privacy:metadata:local_cpdlog_entry');

        $collection->add_database_table('local_cpdlog_cohortchoice', [
            'userid' => 'privacy:metadata:local_cpdlog_cohortchoice:userid',
            'periodid' => 'privacy:metadata:local_cpdlog_cohortchoice:periodid',
            'cohortid' => 'privacy:metadata:local_cpdlog_cohortchoice:cohortid',
            'chosenby' => 'privacy:metadata:local_cpdlog_cohortchoice:chosenby',
            'timecreated' => 'privacy:metadata:local_cpdlog_cohortchoice:timecreated',
            'timemodified' => 'privacy:metadata:local_cpdlog_cohortchoice:timemodified',
        ], 'privacy:metadata:local_cpdlog_cohortchoice');

        $collection->add_database_table('local_cpdlog_deletion', [
            'userid' => 'privacy:metadata:local_cpdlog_deletion:userid',
            'requestedby' => 'privacy:metadata:local_cpdlog_deletion:requestedby',
            'status' => 'privacy:metadata:local_cpdlog_deletion:status',
            'entriesdeleted' => 'privacy:metadata:local_cpdlog_deletion:entriesdeleted',
            'filesdeleted' => 'privacy:metadata:local_cpdlog_deletion:filesdeleted',
            'timerequested' => 'privacy:metadata:local_cpdlog_deletion:timerequested',
            'timecompleted' => 'privacy:metadata:local_cpdlog_deletion:timecompleted',
        ], 'privacy:metadata:local_cpdlog_deletion');

        $collection->add_database_table('local_cpdlog_reminder', [
            'userid' => 'privacy:metadata:local_cpdlog_reminder:userid',
            'periodid' => 'privacy:metadata:local_cpdlog_reminder:periodid',
            'daysbefore' => 'privacy:metadata:local_cpdlog_reminder:daysbefore',
            'timesent' => 'privacy:metadata:local_cpdlog_reminder:timesent',
        ], 'privacy:metadata:local_cpdlog_reminder');
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:core_files');
        $collection->add_subsystem_link('core_message', [], 'privacy:metadata:core_message');

        // Staff who configure categories, periods and targets are recorded on those rows.
        foreach (self::CONFIG_TABLES as $table) {
            $collection->add_database_table($table, [
                'usermodified' => 'privacy:metadata:usermodified',
                'timemodified' => 'privacy:metadata:timemodified',
            ], 'privacy:metadata:' . $table);
        }

        return $collection;
    }

    /**
     * Returns the system context when the user has any CPD data, as a member or as staff.
     *
     * @param int $userid The user.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        if (self::user_has_data($userid)) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Adds every user with CPD data to the list, when the context is the system context.
     *
     * @param userlist $userlist The list to add users to.
     */
    public static function get_users_in_context(userlist $userlist) {
        if ($userlist->get_context()->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        foreach (self::ENTRY_USER_FIELDS as $field) {
            $userlist->add_from_sql($field, "SELECT {$field} FROM {local_cpdlog_entry} WHERE {$field} > 0", []);
        }
        // A chooser is 0 once that staff member's own CPD data has been deleted.
        foreach (['userid', 'chosenby'] as $field) {
            $userlist->add_from_sql($field, "SELECT {$field} FROM {local_cpdlog_cohortchoice} WHERE {$field} > 0", []);
        }
        foreach (['userid', 'requestedby'] as $field) {
            $userlist->add_from_sql($field, "SELECT {$field} FROM {local_cpdlog_deletion}", []);
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_cpdlog_reminder}', []);
        foreach (self::CONFIG_TABLES as $table) {
            $userlist->add_from_sql('usermodified', "SELECT usermodified FROM {{$table}} WHERE usermodified > 0", []);
        }
    }

    /**
     * Exports the user's CPD data: their own entries and cohort choices, and their actions as staff.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $system = \context_system::instance();
        if (!in_array($system->id, $contextlist->get_contextids())) {
            return;
        }
        $userid = (int) $contextlist->get_user()->id;
        $writer = writer::with_context($system);
        $component = get_string('pluginname', 'local_cpdlog');

        $categories = $DB->get_records_menu('local_cpdlog_category', null, '', 'id, name');
        $periods = $DB->get_records_menu('local_cpdlog_period', null, '', 'id, name');

        $entries = [];
        foreach ($DB->get_records('local_cpdlog_entry', ['userid' => $userid], 'activitydate, id') as $entry) {
            $entries[] = (object) [
                'category' => $categories[$entry->categoryid] ?? '',
                'period' => $periods[$entry->periodid] ?? '',
                'course' => $entry->coursename,
                'activitydate' => transform::date($entry->activitydate),
                'hours' => format_float($entry->hours, 2),
                'description' => format_text($entry->description, $entry->descriptionformat, ['context' => $system]),
                'status' => $entry->status,
                'source' => $entry->source,
                'externalref' => $entry->externalref,
                'syncstatus' => $entry->syncstatus,
                'timesubmitted' => $entry->timesubmitted ? transform::datetime($entry->timesubmitted) : null,
                'timereviewed' => $entry->timereviewed ? transform::datetime($entry->timereviewed) : null,
                'rejectionreason' => $entry->rejectionreason,
                'timereversed' => $entry->timereversed ? transform::datetime($entry->timereversed) : null,
                'reversalreason' => $entry->reversalreason,
                'timecreated' => transform::datetime($entry->timecreated),
                'timemodified' => transform::datetime($entry->timemodified),
            ];
        }
        if ($entries) {
            $writer->export_data([$component, get_string('privacy:entries', 'local_cpdlog')], (object) ['entries' => $entries]);
        }
        foreach ($DB->get_fieldset_select('local_cpdlog_entry', 'id', 'userid = :userid', ['userid' => $userid]) as $entryid) {
            $subcontext = [$component, get_string('privacy:entries', 'local_cpdlog'), (string) $entryid];
            $writer->export_area_files($subcontext, 'local_cpdlog', 'evidence', $entryid);
        }

        $choices = [];
        foreach ($DB->get_records('local_cpdlog_cohortchoice', ['userid' => $userid]) as $choice) {
            $choices[] = (object) [
                'period' => $periods[$choice->periodid] ?? '',
                'cohort' => $choice->cohortid ? $DB->get_field('cohort', 'name', ['id' => $choice->cohortid]) : null,
                'timemodified' => transform::datetime($choice->timemodified),
            ];
        }
        if ($choices) {
            $subcontext = [$component, get_string('privacy:cohortchoices', 'local_cpdlog')];
            $writer->export_data($subcontext, (object) ['choices' => $choices]);
        }

        // Deletions of the member's own CPD data, as recorded in the register.
        $deletions = [];
        foreach ($DB->get_records('local_cpdlog_deletion', ['userid' => $userid], 'timerequested') as $deletion) {
            $deletions[] = (object) [
                'status' => $deletion->status,
                'entriesdeleted' => $deletion->entriesdeleted,
                'filesdeleted' => $deletion->filesdeleted,
                'timerequested' => transform::datetime($deletion->timerequested),
                'timecompleted' => $deletion->timecompleted ? transform::datetime($deletion->timecompleted) : null,
            ];
        }
        if ($deletions) {
            $subcontext = [$component, get_string('privacy:deletions', 'local_cpdlog')];
            $writer->export_data($subcontext, (object) ['deletions' => $deletions]);
        }

        // Reminders sent to the member before a reporting period closed.
        $reminders = [];
        $sql = 'SELECT r.id, p.name AS period, r.daysbefore, r.timesent
                  FROM {local_cpdlog_reminder} r
                  JOIN {local_cpdlog_period} p ON p.id = r.periodid
                 WHERE r.userid = :userid
              ORDER BY r.timesent';
        foreach ($DB->get_records_sql($sql, ['userid' => $userid]) as $reminder) {
            $reminders[] = (object) [
                'period' => format_string($reminder->period),
                'daysbefore' => $reminder->daysbefore,
                'timesent' => transform::datetime($reminder->timesent),
            ];
        }
        if ($reminders) {
            $subcontext = [$component, get_string('privacy:reminders', 'local_cpdlog')];
            $writer->export_data($subcontext, (object) ['reminders' => $reminders]);
        }

        // As staff, only the fact of each action is exported, not other members' CPD details.
        $actions = [];
        $sql = 'SELECT id, reviewedby, timereviewed, reversedby, timereversed, usermodified, timemodified
                  FROM {local_cpdlog_entry}
                 WHERE userid <> :userid AND (reviewedby = :u1 OR reversedby = :u2 OR usermodified = :u3)';
        $params = ['userid' => $userid, 'u1' => $userid, 'u2' => $userid, 'u3' => $userid];
        foreach ($DB->get_records_sql($sql, $params) as $entry) {
            $actions[] = (object) [
                'entryid' => $entry->id,
                'reviewed' => $entry->reviewedby == $userid ? transform::datetime($entry->timereviewed) : null,
                'reversed' => $entry->reversedby == $userid ? transform::datetime($entry->timereversed) : null,
                'lastchanged' => $entry->usermodified == $userid ? transform::datetime($entry->timemodified) : null,
            ];
        }
        foreach ($DB->get_records('local_cpdlog_cohortchoice', ['chosenby' => $userid]) as $choice) {
            $actions[] = (object) ['cohortchoiceid' => $choice->id, 'chosen' => transform::datetime($choice->timemodified)];
        }
        foreach ($DB->get_records('local_cpdlog_deletion', ['requestedby' => $userid]) as $deletion) {
            $actions[] = (object) ['deletionid' => $deletion->id, 'requested' => transform::datetime($deletion->timerequested)];
        }
        foreach (self::CONFIG_TABLES as $table) {
            foreach ($DB->get_records($table, ['usermodified' => $userid], '', 'id, timemodified') as $row) {
                $actions[] = (object) [$table => $row->id, 'lastchanged' => transform::datetime($row->timemodified)];
            }
        }
        if ($actions) {
            $subcontext = [$component, get_string('privacy:staffactions', 'local_cpdlog')];
            $writer->export_data($subcontext, (object) ['actions' => $actions]);
        }
    }

    /**
     * Deletes only the reminder log: CPD entries are retained compliance records that staff delete
     * manually (decision 12).
     *
     * @param \context $context The context.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            $DB->delete_records('local_cpdlog_reminder');
        }
    }

    /**
     * Deletes only the user's reminder log: CPD entries are retained compliance records that staff
     * delete manually (decision 12).
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_SYSTEM) {
                $DB->delete_records('local_cpdlog_reminder', ['userid' => $contextlist->get_user()->id]);
            }
        }
    }

    /**
     * Deletes only the users' reminder log: CPD entries are retained compliance records that staff
     * delete manually (decision 12).
     *
     * @param approved_userlist $userlist The approved users.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        if ($userlist->get_context()->contextlevel != CONTEXT_SYSTEM || !$userlist->get_userids()) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $DB->delete_records_select('local_cpdlog_reminder', "userid {$insql}", $params);
    }

    /**
     * Whether the user has any CPD data, as a member or as staff.
     *
     * @param int $userid The user.
     * @return bool
     */
    private static function user_has_data(int $userid): bool {
        global $DB;
        $params = ['u1' => $userid, 'u2' => $userid, 'u3' => $userid, 'u4' => $userid];
        $select = 'userid = :u1 OR reviewedby = :u2 OR reversedby = :u3 OR usermodified = :u4';
        if ($DB->record_exists_select('local_cpdlog_entry', $select, $params)) {
            return true;
        }
        $select = 'userid = :u1 OR chosenby = :u2';
        if ($DB->record_exists_select('local_cpdlog_cohortchoice', $select, ['u1' => $userid, 'u2' => $userid])) {
            return true;
        }
        $select = 'userid = :u1 OR requestedby = :u2';
        if ($DB->record_exists_select('local_cpdlog_deletion', $select, ['u1' => $userid, 'u2' => $userid])) {
            return true;
        }
        if ($DB->record_exists('local_cpdlog_reminder', ['userid' => $userid])) {
            return true;
        }
        foreach (self::CONFIG_TABLES as $table) {
            if ($DB->record_exists($table, ['usermodified' => $userid])) {
                return true;
            }
        }
        return false;
    }
}
