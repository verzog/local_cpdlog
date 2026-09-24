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
 * Privacy provider for the CPD logbook plugin.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
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
 * All data is held at system context. Exports cover a member's own entries and cohort choices, and
 * a staff member's own actions. Deletion is deliberately not automatic: CPD entries are compliance
 * records that SCCA retains, and deletions are made manually by staff (see docs/decisions.md).
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
        foreach (['userid', 'chosenby'] as $field) {
            $userlist->add_from_sql($field, "SELECT {$field} FROM {local_cpdlog_cohortchoice}", []);
        }
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
     * Does not delete: CPD entries are retained compliance records and staff delete them manually.
     *
     * @param \context $context The context.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        unset($context);
    }

    /**
     * Does not delete: CPD entries are retained compliance records and staff delete them manually.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        unset($contextlist);
    }

    /**
     * Does not delete: CPD entries are retained compliance records and staff delete them manually.
     *
     * @param approved_userlist $userlist The approved users.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        unset($userlist);
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
        foreach (self::CONFIG_TABLES as $table) {
            if ($DB->record_exists($table, ['usermodified' => $userid])) {
                return true;
            }
        }
        return false;
    }
}
