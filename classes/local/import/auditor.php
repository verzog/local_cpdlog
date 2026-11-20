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
 * Audits CPD history from another system against the logbook.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local\import;

use local_cpdlog\local\data_deleter;
use local_cpdlog\local\entry_manager;
use local_cpdlog\local\imis\connection;
use local_cpdlog\local\push\payload;
use local_cpdlog\local\push\pusher;
use local_cpdlog\persistent\category;
use local_cpdlog\persistent\entry;

/**
 * Compares a source's CPD history with the logbook, without changing either (decision 25).
 *
 * Each category's hours on a source row is one candidate entry. A run records every candidate with
 * its outcome, and everything an import needs, so importing later does not read the source again:
 * - new: can be imported;
 * - existing: already imported, unchanged;
 * - changed: already imported, but the source now differs (listed, not changed);
 * - conflict: looks like an entry already in the logbook (same member, day, category and hours),
 *   so staff decide;
 * - skipped: a conflict staff chose to skip before;
 * - unmatched: cannot be imported, with the reason.
 */
class auditor
{
    /** @var string Table of runs. */
    const RUN_TABLE = 'local_cpdlog_import_run';

    /** @var string Table of audited rows. */
    const ROW_TABLE = 'local_cpdlog_import_row';

    /** @var string[] Every outcome, in the order they are listed. */
    const OUTCOMES = ['new', 'conflict', 'changed', 'unmatched', 'existing', 'skipped'];

    /** @var int Days runs are kept; rows staff decided on are kept for good. */
    const KEEP_DAYS = 90;

    /**
     * Returns the logbook source of entries imported from a source.
     *
     * @param string $source imis or csv.
     * @return string
     */
    public static function entry_source(string $source): string {
        return $source === 'imis' ? entry::SOURCE_IMIS : entry::SOURCE_IMPORT;
    }

    /**
     * Audits a source.
     *
     * @param source $source The source.
     * @param string $memberid Only this member, by their ID in the source; '' for everyone.
     * @param int $requestedby The staff member running it; 0 for the nightly task.
     * @return \stdClass The run.
     * @throws \moodle_exception If the mapping is incomplete or the source cannot be read.
     */
    public static function run(source $source, string $memberid, int $requestedby): \stdClass {
        global $DB;
        $mapping = mapping::get();
        if ($missing = $mapping->get_missing()) {
            throw new \moodle_exception('import:mappingincomplete', 'local_cpdlog', '', implode(', ', $missing));
        }
        $memberid = trim($memberid);
        $rows = $source->get_rows($memberid);

        $now = time();
        $run = (object) [
            'source' => $source->get_name(),
            'memberid' => $memberid === '' ? null : $memberid,
            'status' => 'audited',
            'counts' => null,
            'requestedby' => $requestedby,
            'importedby' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $run->id = $DB->insert_record(self::RUN_TABLE, $run);

        $categories = [];
        foreach (category::get_records() as $category) {
            $categories[$category->get('shortname')] = (int) $category->get('id');
        }
        $auditor = new self($run, $categories);
        $records = [];
        foreach ($rows as $row) {
            $mapped = $mapping->map($row);
            if ($memberid !== '' && $mapped->memberid !== $memberid) {
                continue;
            }
            array_push($records, ...$auditor->classify($mapped));
        }
        $DB->insert_records(self::ROW_TABLE, $records);

        $counts = array_fill_keys(self::OUTCOMES, 0);
        foreach ($records as $record) {
            $counts[$record->outcome]++;
        }
        $run->counts = json_encode($counts);
        $DB->set_field(self::RUN_TABLE, 'counts', $run->counts, ['id' => $run->id]);
        self::prune();
        return $run;
    }

    /** @var \stdClass The run. */
    protected \stdClass $run;

    /** @var int[] Category ids by short name. */
    protected array $categories;

    /** @var array Users found, by member ID (null when none). */
    protected array $users = [];

    /** @var array[] Entries by user id, for spotting likely duplicates. */
    protected array $entries = [];

    /**
     * Creates an auditor for one run.
     *
     * @param \stdClass $run The run.
     * @param int[] $categories Category ids by short name.
     */
    protected function __construct(\stdClass $run, array $categories) {
        $this->run = $run;
        $this->categories = $categories;
    }

    /**
     * Classifies one mapped source row: one record per category with hours.
     *
     * @param \stdClass $mapped From mapping::map().
     * @return \stdClass[] Records for the row table.
     */
    protected function classify(\stdClass $mapped): array {
        $base = [
            'runid' => $this->run->id,
            'memberid' => \core_text::substr($mapped->memberid, 0, 100) ?: null,
            'userid' => null,
            'categoryid' => null,
            'periodid' => null,
            'activitydate' => (int) $mapped->date,
            'hours' => 0,
            'title' => $mapped->title ?: null,
            'provider' => $mapped->provider ?: null,
            'description' => $mapped->description ?: null,
            'reason' => null,
            'matchentryid' => null,
            'decision' => null,
            'decidedby' => 0,
            'timedecided' => null,
            'entryid' => null,
            'timecreated' => $this->run->timecreated,
        ];
        $unmatched = fn(string $reason, string $rowkey = '') => [(object) array_merge($base, [
            'rowkey' => \core_text::substr($rowkey, 0, 255),
            'outcome' => 'unmatched',
            'reason' => $reason,
        ])];

        if ($mapped->key === '') {
            return $unmatched('nokey');
        }
        if ($mapped->date === null) {
            return $unmatched('baddate', $mapped->key);
        }
        // Records Moodle wrote to iMIS (decision 26) are never imported back.
        $pushedentry = $this->run->source === 'imis' ? pusher::find_pushed($mapped->key, $mapped->memberid) : null;
        if ($pushedentry || ($mapped->origin !== '' && $mapped->origin === payload::origin())) {
            return [(object) array_merge($base, [
                'rowkey' => \core_text::substr($mapped->key, 0, 255),
                'outcome' => 'existing',
                'reason' => 'pushed',
                'matchentryid' => $pushedentry,
            ])];
        }
        if (!$mapped->hours) {
            return $unmatched('nohours', $mapped->key);
        }
        $user = $this->find_user($mapped->memberid);
        $period = entry_manager::find_period($mapped->date);

        $records = [];
        foreach ($mapped->hours as $shortname => $hours) {
            // A key is never shortened: two records, or two categories, would then share one.
            $rowkey = $mapped->key . '/' . $shortname;
            if (\core_text::strlen($rowkey) > 255) {
                return $unmatched('longkey', \core_text::substr($mapped->key, 0, 200) . '…');
            }
            $record = (object) array_merge($base, [
                'rowkey' => $rowkey,
                'hours' => $hours,
                'outcome' => 'new',
            ]);
            $record->userid = $user ? (int) $user->id : null;
            $record->categoryid = $this->categories[$shortname] ?? null;
            $record->periodid = $period ? (int) $period->get('id') : null;
            $this->decide($record);
            $records[] = $record;
        }
        return $records;
    }

    /**
     * Works out a candidate entry's outcome.
     *
     * @param \stdClass $record The record, outcome new until shown otherwise.
     */
    protected function decide(\stdClass $record): void {
        global $DB;
        if ($record->hours > entry::MAX_HOURS) {
            // Too large for the hours column too, so it is not kept.
            $record->outcome = 'unmatched';
            $record->reason = 'badhours';
            $record->hours = 0;
            return;
        }
        $existing = $DB->get_record(entry::TABLE, [
            'source' => self::entry_source($this->run->source),
            'externalref' => $record->rowkey,
        ]);
        if ($existing) {
            $record->matchentryid = (int) $existing->id;
            $same = (int) $existing->userid === (int) $record->userid
                && (int) $existing->categoryid === (int) $record->categoryid
                && self::day($existing->activitydate) === self::day($record->activitydate)
                && abs((float) $existing->hours - (float) $record->hours) < 0.005
                // A blank title or provider was imported as a default, so only a given one is compared.
                && ((string) $record->title === '' || $record->title === $existing->activityname)
                && ((string) $record->provider === '' || $record->provider === $existing->provider)
                && (string) $record->description === (string) $existing->description;
            $record->outcome = $same ? 'existing' : 'changed';
            return;
        }
        $required = ['nomember' => $record->userid, 'nocategory' => $record->categoryid, 'noperiod' => $record->periodid];
        foreach ($required as $reason => $value) {
            if ($value === null) {
                $record->outcome = 'unmatched';
                $record->reason = $reason;
                return;
            }
        }
        if (self::blocked_by_deletion($record->userid, $record->activitydate)) {
            $record->outcome = 'unmatched';
            $record->reason = 'deleted';
            return;
        }
        $skipped = $DB->record_exists_sql(
            'SELECT 1 FROM {' . self::ROW_TABLE . '} r JOIN {' . self::RUN_TABLE . '} u ON u.id = r.runid
              WHERE r.rowkey = :rowkey AND r.decision = :skip AND u.source = :source',
            ['rowkey' => $record->rowkey, 'skip' => 'skip', 'source' => $this->run->source]
        );
        if ($skipped) {
            $record->outcome = 'skipped';
            return;
        }
        foreach ($this->entries_for($record->userid) as $entry) {
            // Entries released from course completions keep the time of day, so days are compared.
            if (
                self::day($entry->activitydate) === self::day($record->activitydate)
                && (int) $entry->categoryid === (int) $record->categoryid
                && abs((float) $entry->hours - (float) $record->hours) < 0.005
            ) {
                $record->outcome = 'conflict';
                $record->matchentryid = (int) $entry->id;
                return;
            }
        }
    }

    /**
     * Returns the calendar day a time falls on, in the site timezone.
     *
     * @param int|string $time The time.
     * @return string YYYY-MM-DD.
     */
    protected static function day($time): string {
        return (new \DateTimeImmutable('@' . (int) $time))->setTimezone(\core_date::get_server_timezone_object())->format('Y-m-d');
    }

    /**
     * Finds the Moodle user a source member ID belongs to, as the import setting says to match them.
     *
     * @param string $memberid The member ID.
     * @return \stdClass|null
     */
    protected function find_user(string $memberid): ?\stdClass {
        if (!array_key_exists($memberid, $this->users)) {
            $match = (string) get_config('local_cpdlog', 'importmatch');
            $this->users[$memberid] = in_array($match, ['idnumber', 'username', 'email'], true)
                ? connection::find_user_by($match, $memberid)
                : connection::find_user($memberid);
        }
        return $this->users[$memberid];
    }

    /**
     * Returns a member's entries that count or may count: not rejected or reversed.
     *
     * @param int $userid The member.
     * @return \stdClass[]
     */
    protected function entries_for(int $userid): array {
        global $DB;
        if (!isset($this->entries[$userid])) {
            [$insql, $params] = $DB->get_in_or_equal(
                [entry::STATUS_REJECTED, entry::STATUS_REVERSED],
                SQL_PARAMS_NAMED,
                'status',
                false
            );
            $this->entries[$userid] = $DB->get_records_select(
                entry::TABLE,
                "userid = :userid AND status {$insql}",
                $params + ['userid' => $userid],
                '',
                'id, activitydate, categoryid, hours'
            );
        }
        return $this->entries[$userid];
    }

    /**
     * Whether a deletion of the member's CPD data is queued, or ran after this activity, so the
     * history must not come back.
     *
     * @param int $userid The member.
     * @param int $activitydate The activity's date.
     * @return bool
     */
    public static function blocked_by_deletion(int $userid, int $activitydate): bool {
        global $DB;
        $select = 'userid = :userid AND (status = :queued OR (status = :done AND timecompleted >= :date))';
        return $DB->record_exists_select(data_deleter::TABLE, $select, [
            'userid' => $userid,
            'queued' => data_deleter::STATUS_QUEUED,
            'done' => data_deleter::STATUS_DONE,
            'date' => $activitydate,
        ]);
    }

    /**
     * Deletes runs older than the keeping period, except rows staff decided on and their runs.
     */
    public static function prune(): void {
        global $DB;
        $cutoff = time() - self::KEEP_DAYS * DAYSECS;
        $old = $DB->get_fieldset_select(self::RUN_TABLE, 'id', 'timecreated < :cutoff', ['cutoff' => $cutoff]);
        if (!$old) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($old, SQL_PARAMS_NAMED);
        $DB->delete_records_select(self::ROW_TABLE, "runid {$insql} AND decision IS NULL", $params);
        $DB->delete_records_select(
            self::RUN_TABLE,
            "id {$insql} AND NOT EXISTS (SELECT 1 FROM {" . self::ROW_TABLE . "} r WHERE r.runid = {" . self::RUN_TABLE . "}.id)",
            $params
        );
    }
}
