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

/**
 * Privacy provider for the CPD logbook plugin.
 *
 * Declares the personal data the plugin stores. Export and delete are added with the code that
 * first writes entries (phase 2), once the retention position on approved entries is settled.
 */
class provider implements \core_privacy\local\metadata\provider
{
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
        foreach (['local_cpdlog_category', 'local_cpdlog_period', 'local_cpdlog_target'] as $table) {
            $collection->add_database_table($table, [
                'usermodified' => 'privacy:metadata:usermodified',
                'timemodified' => 'privacy:metadata:timemodified',
            ], 'privacy:metadata:' . $table);
        }

        return $collection;
    }
}
