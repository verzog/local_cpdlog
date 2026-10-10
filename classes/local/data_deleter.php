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
 * Staff deletion of a member's CPD data.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local;

use local_cpdlog\event\member_data_deleted;
use local_cpdlog\persistent\category;
use local_cpdlog\persistent\entry;
use local_cpdlog\persistent\period;
use local_cpdlog\persistent\target;

/**
 * Staff deletion of a member's CPD data (decision 12).
 *
 * CPD data is never deleted automatically. Staff with local/cpdlog:deletedata queue a deletion for one
 * member, which an adhoc task carries out. The deletion removes the member's entries in every status,
 * their evidence files and the cohort choices made for them. Where the member acted as staff, their
 * name is cleared from other members' records but the records themselves are kept. Each deletion is
 * kept in a register, and the site setting local_cpdlog/enabledeletion switches the whole tool off:
 * switching it off cancels queued deletions, and a deletion whose task runs while it is off is cancelled.
 *
 * Callers must still check login, local/cpdlog:deletedata and the session key.
 */
final class data_deleter
{
    /** @var string The register of deletions. */
    const TABLE = 'local_cpdlog_deletion';

    /** @var string A deletion waiting for its task. */
    const STATUS_QUEUED = 'queued';

    /** @var string A deletion that has run. */
    const STATUS_DONE = 'done';

    /** @var string A deletion that was not run because the tool was switched off. */
    const STATUS_CANCELLED = 'cancelled';

    /** @var string Lock type taken per member while queuing a deletion or copying image blog CPD. */
    const LOCK_TYPE = 'local_cpdlog_deletion';

    /**
     * Returns the lock key for a member, used with LOCK_TYPE.
     *
     * @param int $userid The member.
     * @return string
     */
    public static function lock_key(int $userid): string {
        return 'user' . $userid;
    }

    /**
     * Whether the site setting allows deletions.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool) get_config('local_cpdlog', 'enabledeletion');
    }

    /**
     * Counts what a deletion for the member would remove or change now.
     *
     * @param int $userid The member.
     * @return \stdClass entries (int[] keyed by status), totalentries, files, choices and stafftraces
     *                   (other members' records and settings that name the member as staff).
     */
    public static function count(int $userid): \stdClass {
        global $DB;
        $entries = [];
        $sql = 'SELECT status, COUNT(1) FROM {' . entry::TABLE . '} WHERE userid = :userid GROUP BY status';
        foreach ($DB->get_records_sql_menu($sql, ['userid' => $userid]) as $status => $count) {
            $entries[$status] = (int) $count;
        }

        $files = 0;
        $entryids = $DB->get_fieldset_select(entry::TABLE, 'id', 'userid = :userid', ['userid' => $userid]);
        if ($entryids) {
            [$insql, $params] = $DB->get_in_or_equal($entryids, SQL_PARAMS_NAMED);
            $params += [
                'contextid' => \context_system::instance()->id,
                'component' => 'local_cpdlog',
                'filearea' => entry_manager::EVIDENCE_AREA,
            ];
            $select = "contextid = :contextid AND component = :component AND filearea = :filearea
                       AND itemid {$insql} AND filename <> '.'";
            $files = $DB->count_records_select('files', $select, $params);
        }

        return (object) [
            'entries' => $entries,
            'totalentries' => array_sum($entries),
            'files' => $files,
            'choices' => $DB->count_records(target_resolver::CHOICE_TABLE, ['userid' => $userid]),
            'stafftraces' => self::count_staff_traces($userid),
        ];
    }

    /**
     * Returns the member's deletion that is waiting to run, if any.
     *
     * @param int $userid The member.
     * @return \stdClass|null The register record.
     */
    public static function get_queued(int $userid): ?\stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['userid' => $userid, 'status' => self::STATUS_QUEUED]) ?: null;
    }

    /**
     * Records a deletion for the member and queues the task that carries it out.
     *
     * @param int $userid The member.
     * @param int $requestedby The staff member asking.
     * @return int The register id.
     * @throws \moodle_exception If deletions are switched off, the member does not exist, or one is already queued.
     */
    public static function queue(int $userid, int $requestedby): int {
        global $DB;
        if (!self::is_enabled()) {
            throw new \moodle_exception('error:deletiondisabled', 'local_cpdlog');
        }
        // Accounts already deleted in Moodle keep their CPD data (decision 12), so they can be chosen too.
        if (!$DB->record_exists('user', ['id' => $userid])) {
            throw new \moodle_exception('invaliduser', 'error');
        }
        // One deletion per member at a time: the check and the insert run under a lock, and the register
        // row and its task are written together, so neither can exist without the other.
        $lock = \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE)->get_lock(self::lock_key($userid), 5);
        if (!$lock) {
            throw new \moodle_exception('error:deletionqueued', 'local_cpdlog');
        }
        try {
            if (self::get_queued($userid)) {
                throw new \moodle_exception('error:deletionqueued', 'local_cpdlog');
            }
            $transaction = $DB->start_delegated_transaction();
            $id = $DB->insert_record(self::TABLE, (object) [
                'userid' => $userid,
                'requestedby' => $requestedby,
                'status' => self::STATUS_QUEUED,
                'timerequested' => time(),
            ]);
            $task = new \local_cpdlog\task\delete_member_data();
            $task->set_custom_data(['deletionid' => $id]);
            $task->set_userid($requestedby);
            \core\task\manager::queue_adhoc_task($task);
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }
        return (int) $id;
    }

    /**
     * Cancels every queued deletion. Called when deletions are switched off, so switching them back on
     * before the tasks run does not let the cancelled deletions go ahead.
     *
     * @return int How many deletions were cancelled.
     */
    public static function cancel_queued(): int {
        global $DB;
        $count = $DB->count_records(self::TABLE, ['status' => self::STATUS_QUEUED]);
        $sql = 'UPDATE {' . self::TABLE . '} SET status = :cancelled, timecompleted = :now WHERE status = :queued';
        $DB->execute($sql, ['cancelled' => self::STATUS_CANCELLED, 'now' => time(), 'queued' => self::STATUS_QUEUED]);
        return $count;
    }

    /**
     * Cancels queued deletions when the tool is switched off in the site settings.
     *
     * Without this, switching the tool off and on again before the queued tasks ran would let them go ahead.
     */
    public static function setting_updated(): void {
        if (!self::is_enabled()) {
            self::cancel_queued();
        }
    }

    /**
     * Carries out a queued deletion, or cancels it if deletions have since been switched off.
     *
     * Everything is deleted in one transaction, so a failure leaves the deletion queued and the task
     * retries it. A deletion that is not queued is left alone, so running the task twice is harmless.
     *
     * @param int $deletionid The register id.
     */
    public static function run(int $deletionid): void {
        global $DB;
        $deletion = $DB->get_record(self::TABLE, ['id' => $deletionid]);
        if (!$deletion || $deletion->status !== self::STATUS_QUEUED) {
            return;
        }
        if (!self::is_enabled()) {
            $DB->update_record(self::TABLE, (object) [
                'id' => $deletionid,
                'status' => self::STATUS_CANCELLED,
                'timecompleted' => time(),
            ]);
            return;
        }

        $userid = (int) $deletion->userid;
        $transaction = $DB->start_delegated_transaction();

        $fs = get_file_storage();
        $systemid = \context_system::instance()->id;
        $entries = 0;
        $files = 0;
        foreach ($DB->get_fieldset_select(entry::TABLE, 'id', 'userid = :userid', ['userid' => $userid]) as $entryid) {
            $areafiles = $fs->get_area_files($systemid, 'local_cpdlog', entry_manager::EVIDENCE_AREA, $entryid, 'id', false);
            $files += count($areafiles);
            $fs->delete_area_files($systemid, 'local_cpdlog', entry_manager::EVIDENCE_AREA, $entryid);
            $entries++;
        }
        $DB->delete_records(entry::TABLE, ['userid' => $userid]);
        $DB->delete_records(target_resolver::CHOICE_TABLE, ['userid' => $userid]);
        $DB->delete_records(reminder::TABLE, ['userid' => $userid]);
        $DB->delete_records(course_cpd::TABLE, ['userid' => $userid]);
        self::clear_staff_traces($userid);

        // Image blog awards that exist now are never copied back into the logbook (decision 18).
        if (imageblog_sync::is_installed()) {
            $deletion->imageblogawardid = (int) $DB->get_field_sql(
                'SELECT MAX(id) FROM {' . imageblog_sync::AWARD_TABLE . '} WHERE userid = :userid',
                ['userid' => $userid]
            );
        }
        $deletion->status = self::STATUS_DONE;
        $deletion->entriesdeleted = $entries;
        $deletion->filesdeleted = $files;
        $deletion->timecompleted = time();
        $DB->update_record(self::TABLE, $deletion);
        member_data_deleted::create_from_deletion($deletion)->trigger();

        $transaction->allow_commit();
    }

    /**
     * Counts other members' records and staff settings that name the member as the staff member who acted.
     *
     * @param int $userid The member.
     * @return int
     */
    private static function count_staff_traces(int $userid): int {
        global $DB;
        $count = 0;
        foreach (self::staff_fields() as [$table, $field]) {
            $select = "{$field} = :staffid";
            $params = ['staffid' => $userid];
            if (in_array($table, [entry::TABLE, target_resolver::CHOICE_TABLE, course_cpd::TABLE], true)) {
                // The member's own rows are deleted, not changed.
                $select .= ' AND userid <> :userid';
                $params['userid'] = $userid;
            }
            $count += $DB->count_records_select($table, $select, $params);
        }
        return $count;
    }

    /**
     * Clears the member's name from other members' records and from staff settings, keeping the records.
     *
     * Nullable fields become null; required ones become 0, which shows as no user.
     *
     * @param int $userid The member.
     */
    private static function clear_staff_traces(int $userid): void {
        global $DB;
        foreach (self::staff_fields() as [$table, $field, $nullable]) {
            $DB->set_field($table, $field, $nullable ? null : 0, [$field => $userid]);
        }
    }

    /**
     * Lists the fields that record a staff member's action, as [table, field, nullable].
     *
     * @return array[]
     */
    private static function staff_fields(): array {
        return [
            [entry::TABLE, 'reviewedby', true],
            [entry::TABLE, 'reversedby', true],
            [entry::TABLE, 'usermodified', false],
            [target_resolver::CHOICE_TABLE, 'chosenby', false],
            [category::TABLE, 'usermodified', false],
            [period::TABLE, 'usermodified', false],
            [target::TABLE, 'usermodified', false],
            [course_cpd::TABLE, 'actionedby', false],
        ];
    }
}
