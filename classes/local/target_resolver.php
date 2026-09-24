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
 * Works out which CPD targets apply to a member, and which members have cohort conflicts.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\local;

use local_cpdlog\persistent\target;

/**
 * Works out which CPD targets apply to a member, and which members have cohort conflicts.
 *
 * All-members targets (no cohort) apply to everyone. A cohort's targets are added for its members.
 * A member in two or more cohorts that have targets in the same period is a conflict: until staff
 * choose which cohort's targets to add (or none), only the all-members targets apply. A choice that
 * no longer fits, because the member has left the chosen cohort or it has no targets, is ignored.
 */
final class target_resolver
{
    /** @var string Table recording staff choices for conflicts. */
    const CHOICE_TABLE = 'local_cpdlog_cohortchoice';

    /**
     * Returns the ids of a member's cohorts that have targets in a period.
     *
     * @param int $userid The member.
     * @param int $periodid The period.
     * @return int[]
     */
    public static function get_target_cohortids(int $userid, int $periodid): array {
        global $DB;
        $sql = 'SELECT DISTINCT cm.cohortid
                  FROM {cohort_members} cm
                  JOIN {' . target::TABLE . '} t ON t.cohortid = cm.cohortid
                 WHERE cm.userid = :userid AND t.periodid = :periodid
              ORDER BY cm.cohortid';
        return array_map('intval', $DB->get_fieldset_sql($sql, ['userid' => $userid, 'periodid' => $periodid]));
    }

    /**
     * Returns the staff choice for a member in a period, if one is recorded.
     *
     * @param int $userid The member.
     * @param int $periodid The period.
     * @return \stdClass|null The choice record; its cohortid is null when no cohort's targets are added.
     */
    public static function get_choice(int $userid, int $periodid): ?\stdClass {
        global $DB;
        return $DB->get_record(self::CHOICE_TABLE, ['userid' => $userid, 'periodid' => $periodid]) ?: null;
    }

    /**
     * Returns the cohort whose targets are added for a member, or null for none.
     *
     * @param int $userid The member.
     * @param int $periodid The period.
     * @return int|null
     */
    public static function get_added_cohortid(int $userid, int $periodid): ?int {
        $cohortids = self::get_target_cohortids($userid, $periodid);
        $choice = self::get_choice($userid, $periodid);
        if ($choice && self::choice_fits($choice, $cohortids)) {
            return $choice->cohortid === null ? null : (int) $choice->cohortid;
        }
        return count($cohortids) === 1 ? $cohortids[0] : null;
    }

    /**
     * Returns the targets that apply to a member in a period.
     *
     * @param int $userid The member.
     * @param int $periodid The period.
     * @return target[] All-members targets, then the added cohort's targets, as a list.
     */
    public static function get_applicable_targets(int $userid, int $periodid): array {
        $select = 'periodid = :periodid AND cohortid IS NULL';
        $targets = target::get_records_select($select, ['periodid' => $periodid], 'sortorder, name');
        $cohortid = self::get_added_cohortid($userid, $periodid);
        if ($cohortid !== null) {
            $params = ['periodid' => $periodid, 'cohortid' => $cohortid];
            $targets = array_merge($targets, target::get_records($params, 'sortorder, name'));
        }
        return array_values($targets);
    }

    /**
     * Lists the members of a period who are in two or more cohorts with targets.
     *
     * @param int $periodid The period.
     * @return \stdClass[] Keyed by user id; each has userid, cohortids (int[]), choice (record or null)
     *                     and resolved (bool: a choice is recorded and still fits).
     */
    public static function get_conflicts(int $periodid): array {
        global $DB;
        $sql = 'SELECT DISTINCT cm.userid, cm.cohortid
                  FROM {cohort_members} cm
                  JOIN {' . target::TABLE . '} t ON t.cohortid = cm.cohortid AND t.periodid = :periodid
                  JOIN {user} u ON u.id = cm.userid AND u.deleted = 0
              ORDER BY cm.userid, cm.cohortid';
        $cohortsbyuser = [];
        foreach ($DB->get_recordset_sql($sql, ['periodid' => $periodid]) as $row) {
            $cohortsbyuser[(int) $row->userid][] = (int) $row->cohortid;
        }
        $choices = [];
        foreach ($DB->get_records(self::CHOICE_TABLE, ['periodid' => $periodid]) as $choice) {
            $choices[(int) $choice->userid] = $choice;
        }

        $conflicts = [];
        foreach ($cohortsbyuser as $userid => $cohortids) {
            if (count($cohortids) < 2) {
                continue;
            }
            $choice = $choices[$userid] ?? null;
            $conflicts[$userid] = (object) [
                'userid' => $userid,
                'cohortids' => $cohortids,
                'choice' => $choice,
                'resolved' => $choice !== null && self::choice_fits($choice, $cohortids),
            ];
        }
        return $conflicts;
    }

    /**
     * Records which cohort's targets are added for a member with a conflict.
     *
     * @param int $userid The member.
     * @param int $periodid The period.
     * @param int|null $cohortid One of the member's conflicting cohorts, or null to add none.
     * @param int $chosenby The staff member making the choice.
     * @throws \invalid_parameter_exception If the member has no conflict or the cohort is not one of theirs.
     */
    public static function set_choice(int $userid, int $periodid, ?int $cohortid, int $chosenby): void {
        global $DB;
        $cohortids = self::get_target_cohortids($userid, $periodid);
        if (count($cohortids) < 2) {
            throw new \invalid_parameter_exception('The member has no cohort conflict in this period.');
        }
        if ($cohortid !== null && !in_array($cohortid, $cohortids, true)) {
            throw new \invalid_parameter_exception('The cohort is not one of the member\'s conflicting cohorts.');
        }

        $now = time();
        $existing = self::get_choice($userid, $periodid);
        if ($existing) {
            $existing->cohortid = $cohortid;
            $existing->chosenby = $chosenby;
            $existing->timemodified = $now;
            $DB->update_record(self::CHOICE_TABLE, $existing);
        } else {
            $DB->insert_record(self::CHOICE_TABLE, (object) [
                'userid' => $userid,
                'periodid' => $periodid,
                'cohortid' => $cohortid,
                'chosenby' => $chosenby,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
    }

    /**
     * Whether a recorded choice still fits the member's cohorts with targets.
     *
     * @param \stdClass $choice The choice record.
     * @param int[] $cohortids The member's cohorts with targets in the period.
     * @return bool
     */
    private static function choice_fits(\stdClass $choice, array $cohortids): bool {
        return $choice->cohortid === null || in_array((int) $choice->cohortid, $cohortids, true);
    }
}
