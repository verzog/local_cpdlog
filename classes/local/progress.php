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
 * Works out a member's CPD progress against their targets.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\local;

use local_cpdlog\persistent\entry;

/**
 * Works out a member's CPD progress against their targets.
 *
 * Only approved hours count towards a target. Hours submitted and waiting for review are reported
 * beside them as pending. Drafts, rejected and reversed entries never count. Entries from iMIS count
 * like any other once approved.
 */
final class progress
{
    /**
     * Returns a member's approved and pending hours in a period, by category.
     *
     * @param int $userid The member.
     * @param int $periodid The period.
     * @return array Two arrays of hours keyed by category id: 'approved' and 'pending'.
     */
    public static function get_hours_by_category(int $userid, int $periodid): array {
        global $DB;
        $sql = 'SELECT categoryid, status, SUM(hours) AS hours
                  FROM {' . entry::TABLE . '}
                 WHERE userid = :userid AND periodid = :periodid AND status IN (:approved, :submitted)
              GROUP BY categoryid, status';
        $params = [
            'userid' => $userid,
            'periodid' => $periodid,
            'approved' => entry::STATUS_APPROVED,
            'submitted' => entry::STATUS_SUBMITTED,
        ];
        $hours = ['approved' => [], 'pending' => []];
        foreach ($DB->get_recordset_sql($sql, $params) as $row) {
            $key = $row->status === entry::STATUS_APPROVED ? 'approved' : 'pending';
            $hours[$key][(int) $row->categoryid] = round((float) $row->hours, 2);
        }
        return $hours;
    }

    /**
     * Returns a member's progress against each target that applies to them in a period.
     *
     * @param int $userid The member.
     * @param int $periodid The period.
     * @return \stdClass[] One per applicable target, in target order: target, required, approved and
     *                     pending hours, met (bool) and percent (0 to 100, of approved against required).
     */
    public static function get_progress(int $userid, int $periodid): array {
        $hours = self::get_hours_by_category($userid, $periodid);
        $progress = [];
        foreach (target_resolver::get_applicable_targets($userid, $periodid) as $target) {
            $categoryids = $target->get_categoryids();
            $required = round((float) $target->get('requiredhours'), 2);
            $approved = self::sum($hours['approved'], $categoryids);
            $progress[] = (object) [
                'target' => $target,
                'required' => $required,
                'approved' => $approved,
                'pending' => self::sum($hours['pending'], $categoryids),
                // Hours are stored with two decimals, so compare in hundredths to avoid float error.
                'met' => (int) round($approved * 100) >= (int) round($required * 100),
                'percent' => (int) min(100, floor($approved / $required * 100)),
            ];
        }
        return $progress;
    }

    /**
     * Adds up the hours in the given categories, or in every category when none are given.
     *
     * @param float[] $hours Hours keyed by category id.
     * @param int[] $categoryids The categories that count; empty for all.
     * @return float
     */
    private static function sum(array $hours, array $categoryids): float {
        if ($categoryids) {
            $hours = array_intersect_key($hours, array_flip($categoryids));
        }
        return round(array_sum($hours), 2);
    }
}
