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
 * Imports audited CPD history into the logbook.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local\import;

use local_cpdlog\event\entry_created;
use local_cpdlog\local\data_deleter;
use local_cpdlog\persistent\entry;

/**
 * Turns audited rows into approved, read-only logbook entries (decision 25). Never writes back to
 * the source.
 */
class importer
{
    /**
     * Queues the import of a run's new rows, for the staff member who asked.
     *
     * @param int $runid The run.
     * @param int $staffid The staff member importing.
     * @throws \moodle_exception If the run was imported or queued already.
     */
    public static function queue(int $runid, int $staffid): void {
        global $DB;
        $run = $DB->get_record(auditor::RUN_TABLE, ['id' => $runid], '*', MUST_EXIST);
        if ($run->status !== 'audited') {
            throw new \moodle_exception('import:alreadyqueued', 'local_cpdlog');
        }
        $DB->update_record(auditor::RUN_TABLE, (object) [
            'id' => $runid,
            'status' => 'queued',
            'importedby' => $staffid,
            'timemodified' => time(),
        ]);
        $task = new \local_cpdlog\task\import_run();
        $task->set_custom_data(['runid' => $runid]);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Imports a run's new rows. If a member is busy it stops with an error, leaving the run queued,
     * and the task tries again; rows already imported are not imported twice.
     *
     * @param int $runid The run.
     * @return int How many entries were created.
     * @throws \moodle_exception If a member is busy.
     */
    public static function import_run(int $runid): int {
        global $DB;
        $run = $DB->get_record(auditor::RUN_TABLE, ['id' => $runid], '*', MUST_EXIST);
        $imported = 0;
        $rows = $DB->get_records(auditor::ROW_TABLE, ['runid' => $runid, 'outcome' => 'new', 'entryid' => null], 'id');
        foreach ($rows as $row) {
            if (self::import_row($row, $run->source, (int) $run->importedby)) {
                $imported++;
            }
        }
        $DB->update_record(auditor::RUN_TABLE, (object) ['id' => $runid, 'status' => 'imported', 'timemodified' => time()]);
        return $imported;
    }

    /**
     * Returns the conflicts waiting for staff: each record's latest audit, whichever audit that was
     * (everyone, one member, or another file), where it was a conflict and nobody has decided.
     *
     * @return \stdClass[] Audited rows.
     */
    public static function get_open_conflicts(): array {
        global $DB;
        return $DB->get_records_sql(
            'SELECT r.*
               FROM {' . auditor::ROW_TABLE . '} r
               JOIN {' . auditor::RUN_TABLE . '} u ON u.id = r.runid
              WHERE r.outcome = :conflict AND r.decision IS NULL
                AND r.id = (SELECT MAX(r2.id)
                              FROM {' . auditor::ROW_TABLE . '} r2
                              JOIN {' . auditor::RUN_TABLE . '} u2 ON u2.id = r2.runid
                             WHERE r2.rowkey = r.rowkey AND u2.source = u.source)
           ORDER BY r.memberid, r.activitydate, r.id',
            ['conflict' => 'conflict']
        );
    }

    /**
     * Records a staff decision on a conflict: import it anyway, or skip it, now and in later audits.
     *
     * @param int $rowid The audited row.
     * @param string $decision import or skip.
     * @param int $staffid The staff member deciding.
     * @return bool Whether an entry was created.
     * @throws \moodle_exception If the row is not an undecided conflict.
     */
    public static function decide(int $rowid, string $decision, int $staffid): bool {
        global $DB;
        $row = $DB->get_record(auditor::ROW_TABLE, ['id' => $rowid], '*', MUST_EXIST);
        if ($row->outcome !== 'conflict' || $row->decision !== null || !in_array($decision, ['import', 'skip'], true)) {
            throw new \moodle_exception('import:notconflict', 'local_cpdlog');
        }
        $source = $DB->get_field(auditor::RUN_TABLE, 'source', ['id' => $row->runid], MUST_EXIST);
        // The import comes first: if the member is busy it throws, and the decision is not recorded.
        $imported = $decision === 'import' && self::import_row($row, $source, $staffid);
        $DB->update_record(auditor::ROW_TABLE, (object) [
            'id' => $rowid,
            'decision' => $decision,
            'decidedby' => $staffid,
            'timedecided' => time(),
        ]);
        return $imported;
    }

    /**
     * Creates the entry for one audited row, under the member's lock, unless something changed
     * since the audit: the row was imported already, or a deletion of their data was queued.
     *
     * @param \stdClass $row The audited row.
     * @param string $source The run's source: imis or csv.
     * @param int $staffid The staff member importing; 0 for the nightly task.
     * @return bool Whether an entry was created; false when it must not be (already imported, or deleted).
     * @throws \moodle_exception If the member is busy, so the caller can try again later.
     */
    public static function import_row(\stdClass $row, string $source, int $staffid): bool {
        global $DB;
        if (!$row->userid || !$row->categoryid || !$row->periodid) {
            return false;
        }
        $lock = \core\lock\lock_config::get_lock_factory(data_deleter::LOCK_TYPE)
            ->get_lock(data_deleter::lock_key((int) $row->userid), 5);
        if (!$lock) {
            // Not a reason to drop the record: the adhoc task, or staff, try again.
            throw new \moodle_exception('import:locked', 'local_cpdlog');
        }
        try {
            $entrysource = auditor::entry_source($source);
            $params = ['source' => $entrysource, 'ref' => $row->rowkey];
            if (
                entry::record_exists_select('source = :source AND externalref = :ref', $params)
                || auditor::blocked_by_deletion((int) $row->userid, (int) $row->activitydate)
            ) {
                return false;
            }
            $now = time();
            $transaction = $DB->start_delegated_transaction();
            $entry = (new entry(0, (object) [
                'userid' => $row->userid,
                'categoryid' => $row->categoryid,
                'periodid' => $row->periodid,
                'courseid' => null,
                'coursename' => null,
                'activityname' => $row->title ?: get_string('import:untitled', 'local_cpdlog'),
                'provider' => $row->provider ?: get_string('source:' . $entrysource, 'local_cpdlog'),
                'hours' => $row->hours,
                'activitydate' => $row->activitydate,
                'description' => (string) $row->description,
                'descriptionformat' => FORMAT_PLAIN,
                'status' => entry::STATUS_APPROVED,
                'source' => $entrysource,
                'externalref' => $row->rowkey,
                'timesubmitted' => $now,
                'reviewedby' => $staffid ?: null,
                'timereviewed' => $now,
            ]))->create();
            $DB->set_field(auditor::ROW_TABLE, 'entryid', $entry->get('id'), ['id' => $row->id]);
            entry_created::create_from_entry($entry)->trigger();
            $transaction->allow_commit();
            return true;
        } finally {
            $lock->release();
        }
    }
}
