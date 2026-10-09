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
 * Reminds members who are behind on their CPD targets before a reporting period closes.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local;

use local_cpdlog\persistent\entry;
use local_cpdlog\persistent\period;
use local_cpdlog\persistent\target;

/**
 * Reminds members who are behind on their CPD targets before a reporting period closes.
 *
 * Reminders go out a set number of days before each open period's last day (the setting
 * local_cpdlog/reminderdays, default 60 and 14). On each run the smallest of those that has been
 * reached applies, so a site that starts reminding late sends one reminder, not several at once.
 * Members of a period are counted as in the staff reports (decision 16): those who logged CPD in it
 * or belong to a cohort with targets in it. Only members with a target not yet met are reminded, and
 * a log (local_cpdlog_reminder) makes sure nobody gets the same reminder twice. The setting
 * local_cpdlog/remindersenabled switches reminders off.
 */
final class reminder
{
    /** @var string The log of reminders sent. */
    const TABLE = 'local_cpdlog_reminder';

    /** @var int Most reminders sent in one run; the rest go on the next run. */
    const BATCH = 500;

    /**
     * Whether reminders are switched on.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool) get_config('local_cpdlog', 'remindersenabled');
    }

    /**
     * Returns the configured reminder points, in days before a period's last day, largest first.
     *
     * @return int[]
     */
    public static function get_thresholds(): array {
        $days = [];
        foreach (explode(',', (string) get_config('local_cpdlog', 'reminderdays')) as $value) {
            $value = trim($value);
            if ($value !== '' && ctype_digit($value)) {
                $days[(int) $value] = (int) $value;
            }
        }
        krsort($days);
        return array_values($days);
    }

    /**
     * Returns the reminder point that applies on a day, or null if none has been reached.
     *
     * @param int $daysleft Days until the period's last day.
     * @param int[] $thresholds From get_thresholds().
     * @return int|null The smallest reminder point not below the days left.
     */
    public static function applicable_threshold(int $daysleft, array $thresholds): ?int {
        $applicable = null;
        foreach ($thresholds as $threshold) {
            if ($daysleft >= 0 && $daysleft <= $threshold) {
                $applicable = $threshold;
            }
        }
        return $applicable;
    }

    /**
     * Sends the reminders due now.
     *
     * @param int|null $now The time to work from; now if null.
     * @param int $limit Most reminders to send in this run.
     * @return int How many reminders were sent.
     */
    public static function send_due(?int $now = null, int $limit = self::BATCH): int {
        $now = $now ?? time();
        if (!self::is_enabled()) {
            return 0;
        }
        $thresholds = self::get_thresholds();
        $sent = 0;
        // Periods that have opened and not yet closed.
        $open = period::get_records_select('status = :open AND startdate <= :now1 AND enddate > :now2', [
            'open' => period::STATUS_OPEN,
            'now1' => $now,
            'now2' => $now,
        ], 'startdate');
        $lockfactory = \core\lock\lock_config::get_lock_factory(data_deleter::LOCK_TYPE);
        foreach ($open as $period) {
            $daysleft = dates::days_between($now, $period->get_lastday());
            $threshold = self::applicable_threshold($daysleft, $thresholds);
            if ($threshold === null) {
                continue;
            }
            $members = self::get_unreminded_members($period, $threshold);
            foreach ($members as $user) {
                if ($sent >= $limit) {
                    break;
                }
                // Under the lock that queuing a deletion takes, and never while one is queued, so a
                // deletion of the member's CPD data cannot overlap a reminder (decision 17).
                $lock = $lockfactory->get_lock(data_deleter::lock_key((int) $user->id), 5);
                if (!$lock) {
                    continue;
                }
                try {
                    if (!data_deleter::get_queued((int) $user->id) && self::remind($user, $period, $threshold)) {
                        $sent++;
                    }
                } finally {
                    $lock->release();
                }
            }
            $members->close();
            if ($sent >= $limit) {
                break;
            }
        }
        return $sent;
    }

    /**
     * Returns the active members of a period not yet sent this reminder, those with no reminder for
     * the period at all first, so a run cut short by the batch limit reaches them before anyone gets
     * a second reminder.
     *
     * @param period $period The period.
     * @param int $threshold The reminder point.
     * @return \moodle_recordset User records.
     */
    private static function get_unreminded_members(period $period, int $threshold): \moodle_recordset {
        global $DB;
        $sql = 'SELECT u.*
                  FROM {user} u
                  JOIN (SELECT userid FROM {' . entry::TABLE . '} WHERE periodid = :period1
                        UNION
                        SELECT cm.userid
                          FROM {cohort_members} cm
                          JOIN {' . target::TABLE . '} t ON t.cohortid = cm.cohortid
                         WHERE t.periodid = :period2) m ON m.userid = u.id
                 WHERE u.deleted = 0 AND u.suspended = 0
                   AND NOT EXISTS (
                       SELECT 1 FROM {' . self::TABLE . '} r
                        WHERE r.userid = u.id AND r.periodid = :period3 AND r.daysbefore = :threshold
                   )
              ORDER BY (SELECT COUNT(1) FROM {' . self::TABLE . '} p WHERE p.userid = u.id AND p.periodid = :period4), u.id';
        $periodid = (int) $period->get('id');
        return $DB->get_recordset_sql($sql, [
            'period1' => $periodid,
            'period2' => $periodid,
            'period3' => $periodid,
            'period4' => $periodid,
            'threshold' => $threshold,
        ]);
    }

    /**
     * Sends a member the reminder if they have a target not yet met, and logs it.
     *
     * @param \stdClass $user The member.
     * @param period $period The period.
     * @param int $threshold The reminder point.
     * @return bool Whether a reminder was sent.
     */
    private static function remind(\stdClass $user, period $period, int $threshold): bool {
        global $DB;
        $unmet = array_filter(progress::get_progress((int) $user->id, (int) $period->get('id')), fn($row) => !$row->met);
        if (!$unmet) {
            return false;
        }
        // A failed send is not logged, so the next run tries again.
        if (!notifier::period_reminder($user, $period, $unmet)) {
            return false;
        }
        $DB->insert_record(self::TABLE, (object) [
            'userid' => $user->id,
            'periodid' => $period->get('id'),
            'daysbefore' => $threshold,
            'timesent' => time(),
        ]);
        return true;
    }
}
