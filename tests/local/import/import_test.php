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
 * Tests for auditing and importing CPD history.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local\import;

use local_cpdlog\local\data_deleter;
use local_cpdlog\persistent\category;
use local_cpdlog\persistent\entry;
use local_cpdlog\persistent\period;
use local_cpdlog\task\import_run;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for auditing and importing CPD history, from a CSV file.
 */
#[CoversClass(mapping::class)]
#[CoversClass(csv_source::class)]
#[CoversClass(auditor::class)]
#[CoversClass(importer::class)]
#[CoversClass(import_run::class)]
final class import_test extends \advanced_testcase
{
    /** @var \stdClass Member 1001. */
    private \stdClass $ann;

    /** @var \stdClass Member 1002. */
    private \stdClass $ben;

    /** @var \stdClass A staff member. */
    private \stdClass $staff;

    /**
     * Returns the start of a day in Sydney.
     *
     * @param string $day YYYY-MM-DD.
     * @return int
     */
    private static function day(string $day): int {
        return (new \DateTimeImmutable($day, new \DateTimeZone('Australia/Sydney')))->getTimestamp();
    }

    /**
     * Sets up 2025 (closed) and 2026 periods, two members, a staff member and the mapping.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Australia/Sydney');
        $generator = $this->getDataGenerator()->get_plugin_generator('local_cpdlog');
        $generator->create_period([
            'name' => '2025',
            'firstday' => '01/01/2025',
            'lastday' => '31/12/2025',
            'status' => period::STATUS_CLOSED,
        ]);
        $generator->create_period(['name' => '2026', 'firstday' => '01/01/2026', 'lastday' => '31/12/2026']);
        $this->ann = $this->getDataGenerator()->create_user(['idnumber' => '1001', 'email' => 'ann@example.com']);
        $this->ben = $this->getDataGenerator()->create_user(['idnumber' => '1002']);
        $this->staff = $this->getDataGenerator()->create_user();
        foreach (['member' => 'ID', 'key' => 'Row Key', 'date' => 'Date', 'title' => 'Title'] as $name => $column) {
            set_config('importmap' . $name, $column, 'local_cpdlog');
        }
        set_config('importmaphours', "EA = Education_Points\nRP=Reviewing\nXX=Other", 'local_cpdlog');
    }

    /**
     * Returns a CSV source from rows.
     *
     * @param array[] $rows Rows of ID, Row Key, Date, Title, EducationPoints, Reviewing and Other.
     * @return csv_source
     */
    private static function csv(array $rows): csv_source {
        $lines = ["\xEF\xBB\xBFid,ROWKEY,Date,Title,EducationPoints,Reviewing,Other"];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(fn($value) => '"' . str_replace('"', '""', $value) . '"', $row));
        }
        return new csv_source(implode("\r\n", $lines) . "\r\n\r\n");
    }

    /**
     * Returns a run's rows by row key.
     *
     * @param int $runid The run.
     * @return \stdClass[]
     */
    private static function rows(int $runid): array {
        global $DB;
        $rows = [];
        foreach ($DB->get_records(auditor::ROW_TABLE, ['runid' => $runid], 'id') as $row) {
            $rows[$row->rowkey] = $row;
        }
        return $rows;
    }

    /**
     * The mapping reads hour columns per category and dates as iMIS and people write them.
     */
    public function test_mapping(): void {
        $this->assertSame(['EA' => 'EducationPoints', 'RP' => 'Reviewing Points'], mapping::parse_hours(
            " EA=EducationPoints\n\nnonsense\nRP = Reviewing Points "
        ));
        $this->assertSame(self::day('2026-03-10'), mapping::parse_date('2026-03-10T18:30:00'));
        $this->assertSame(self::day('2026-03-10'), mapping::parse_date('10/03/2026'));
        $this->assertSame(self::day('2026-03-01'), mapping::parse_date('1/3/2026'));
        foreach (['31/02/2026', '2026-13-01', 'yesterday', ''] as $bad) {
            $this->assertNull(mapping::parse_date($bad), $bad);
        }

        $mapped = mapping::get()->map(['id' => '1001', 'rowkey' => 'K1', 'DATE' => '2026-03-10', 'Title' => ' Skin course ',
            'EducationPoints' => '1,5', 'Reviewing' => '0', 'Other' => 'n/a']);
        $this->assertSame('1001', $mapped->memberid);
        $this->assertSame('K1', $mapped->key);
        $this->assertSame('Skin course', $mapped->title);
        $this->assertSame(['EA' => 1.5], $mapped->hours);
        $this->assertSame([], mapping::get()->get_missing());

        set_config('importmapkey', '', 'local_cpdlog');
        set_config('importmaphours', '', 'local_cpdlog');
        $this->assertSame(['importmapkey', 'importmaphours'], mapping::get()->get_missing());
        $this->expectException(\moodle_exception::class);
        auditor::run(self::csv([]), '', (int) $this->staff->id);
    }

    /**
     * A CSV file needs a header line.
     */
    public function test_csv_empty(): void {
        $source = self::csv([['1001', 'K1', '2026-03-10', 'A "quoted", title', '1', '', '']]);
        $this->assertSame(['id', 'ROWKEY', 'Date', 'Title', 'EducationPoints', 'Reviewing', 'Other'], $source->get_fields());
        $this->assertSame('A "quoted", title', $source->get_rows()[0]['Title']);
        $this->assertCount(1, $source->get_rows());
        $this->expectException(\moodle_exception::class);
        new csv_source('');
    }

    /**
     * An audit sorts every record into new, conflict, unmatched (with the reason) and so on, and
     * changes nothing; one member can be audited on their own.
     */
    public function test_audit(): void {
        global $DB;
        $ea = category::get_record(['shortname' => 'EA']);
        $existing = $this->getDataGenerator()->get_plugin_generator('local_cpdlog')->create_entry([
            'user' => $this->ann->username,
            'period' => '2026',
            'category' => 'EA',
            'day' => '10/03/2026',
            'hours' => 2,
            'status' => entry::STATUS_APPROVED,
        ]);
        $this->getDataGenerator()->get_plugin_generator('local_cpdlog')->create_entry([
            'user' => $this->ann->username,
            'period' => '2026',
            'category' => 'EA',
            'day' => '12/03/2026',
            'hours' => 3,
            'status' => entry::STATUS_REJECTED,
        ]);
        $source = self::csv([
            ['1001', 'K1', '2026-03-10', 'Same as logbook', '2', '2', ''],
            ['1001', 'K10', '2026-03-12', 'Same as a rejected entry', '3', '', ''],
            ['1001', 'K2', '01/06/2025', 'Closed period', '1', '0.5', ''],
            ['9999', 'K3', '2026-03-10', 'Unknown member', '1', '', ''],
            ['1002', 'K4', '2020-01-01', 'No period', '1', '', ''],
            ['1002', 'K5', 'soon', 'Bad date', '1', '', ''],
            ['1002', 'K6', '2026-04-01', 'No hours', '0', '', ''],
            ['1002', '', '2026-04-01', 'No key', '1', '', ''],
            ['1002', 'K8', '2026-04-01', 'Unknown category', '', '', '2'],
            ['1002', 'K9', '2026-04-01', 'Too many', '10000', '', ''],
        ]);
        $count = $DB->count_records(entry::TABLE);
        $run = auditor::run($source, '', (int) $this->staff->id);
        $this->assertSame($count, $DB->count_records(entry::TABLE));

        $rows = self::rows((int) $run->id);
        $this->assertSame('conflict', $rows['K1/EA']->outcome);
        $this->assertEquals($existing->get('id'), $rows['K1/EA']->matchentryid);
        // The same day and hours in another category, or like a rejected entry, are not conflicts.
        $this->assertSame('new', $rows['K1/RP']->outcome);
        $this->assertSame('new', $rows['K10/EA']->outcome);
        $this->assertSame('new', $rows['K2/EA']->outcome);
        $this->assertSame('new', $rows['K2/RP']->outcome);
        $this->assertEquals(0.5, $rows['K2/RP']->hours);
        $this->assertEquals($this->ann->id, $rows['K2/EA']->userid);
        $this->assertEquals($ea->get('id'), $rows['K2/EA']->categoryid);
        $this->assertSame(self::day('2025-06-01'), (int) $rows['K2/EA']->activitydate);
        $reasons = [
            'K3/EA' => 'nomember',
            'K4/EA' => 'noperiod',
            'K5' => 'baddate',
            'K6' => 'nohours',
            '' => 'nokey',
            'K8/XX' => 'nocategory',
            'K9/EA' => 'badhours',
        ];
        foreach ($reasons as $rowkey => $reason) {
            $this->assertSame('unmatched', $rows[$rowkey]->outcome, $rowkey);
            $this->assertSame($reason, $rows[$rowkey]->reason, $rowkey);
        }
        $counts = json_decode($run->counts, true);
        $expected = ['new' => 4, 'conflict' => 1, 'changed' => 0, 'unmatched' => 7, 'existing' => 0, 'skipped' => 0];
        $this->assertSame($expected, $counts);
        $this->assertEquals($this->staff->id, $run->requestedby);

        // One member on their own.
        $run = auditor::run($source, '1002', (int) $this->staff->id);
        $this->assertSame(['K4/EA', 'K5', 'K6', '', 'K8/XX', 'K9/EA'], array_keys(self::rows((int) $run->id)));
        $this->assertSame('1002', $run->memberid);
    }

    /**
     * Importing creates approved, read-only entries for the new records, in closed periods too,
     * once; a later audit finds them imported, or changed if the source changed.
     */
    public function test_import(): void {
        $this->setAdminUser();
        $source = self::csv([
            ['1001', 'K2', '01/06/2025', 'Closed period', '1', '0.5', ''],
            ['1002', 'K3', '2026-05-01', '', '2', '', ''],
        ]);
        $run = auditor::run($source, '', (int) $this->staff->id);
        importer::queue((int) $run->id, (int) $this->staff->id);
        try {
            importer::queue((int) $run->id, (int) $this->staff->id);
            $this->fail('Expected an exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('import:alreadyqueued', $e->errorcode);
        }
        $this->expectOutputRegex('/CPD history records imported: 3\./');
        $this->runAdhocTasks(import_run::class);

        $entry = entry::get_record(['source' => entry::SOURCE_IMPORT, 'externalref' => 'K2/RP']);
        $this->assertSame(entry::STATUS_APPROVED, $entry->get('status'));
        $this->assertEquals($this->ann->id, $entry->get('userid'));
        $this->assertEquals(0.5, $entry->get('hours'));
        $this->assertSame('Closed period', $entry->get('activityname'));
        $this->assertSame('Import', $entry->get('provider'));
        $this->assertEquals($this->staff->id, $entry->get('reviewedby'));
        $this->assertFalse($entry->is_moodle_owned());
        $this->assertSame('2025', (new period($entry->get('periodid')))->get('name'));
        $untitled = entry::get_record(['source' => entry::SOURCE_IMPORT, 'externalref' => 'K3/EA']);
        $this->assertSame('Imported CPD', $untitled->get('activityname'));
        $this->assertSame('imported', $this->get_run((int) $run->id)->status);
        $imported = self::rows((int) $run->id)['K2/EA'];
        $this->assertNotNull($imported->entryid);
        // A row imported already is not imported again.
        $this->assertFalse(importer::import_row($imported, 'csv', (int) $this->staff->id));

        $source = self::csv([
            ['1001', 'K2', '01/06/2025', 'Closed period', '1', '0.75', ''],
            ['1002', 'K3', '2026-05-01', '', '2', '', ''],
        ]);
        $rows = self::rows((int) auditor::run($source, '', (int) $this->staff->id)->id);
        $this->assertSame('existing', $rows['K2/EA']->outcome);
        $this->assertSame('changed', $rows['K2/RP']->outcome);
        $this->assertSame('existing', $rows['K3/EA']->outcome);
        $this->assertEquals($entry->get('id'), $rows['K2/RP']->matchentryid);
        $this->assertEquals(0.5, (new entry($entry->get('id')))->get('hours'));
    }

    /**
     * Returns a run.
     *
     * @param int $runid The run.
     * @return \stdClass
     */
    private function get_run(int $runid): \stdClass {
        global $DB;
        return $DB->get_record(auditor::RUN_TABLE, ['id' => $runid], '*', MUST_EXIST);
    }

    /**
     * Staff import a conflict anyway, or skip it, which later audits remember.
     */
    public function test_conflicts(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_cpdlog');
        foreach (['10/03/2026', '11/03/2026'] as $day) {
            $generator->create_entry([
                'user' => $this->ann->username,
                'period' => '2026',
                'category' => 'EA',
                'day' => $day,
                'hours' => 2,
                'status' => entry::STATUS_SUBMITTED,
            ]);
        }
        $source = self::csv([
            ['1001', 'K1', '2026-03-10', 'Workshop', '2', '', ''],
            ['1001', 'K2', '2026-03-11', 'Workshop', '2', '', ''],
        ]);
        $rows = self::rows((int) auditor::run($source, '', (int) $this->staff->id)->id);
        $this->assertTrue(importer::decide((int) $rows['K1/EA']->id, 'import', (int) $this->staff->id));
        $this->assertFalse(importer::decide((int) $rows['K2/EA']->id, 'skip', (int) $this->staff->id));
        $this->assertTrue(entry::record_exists_select('externalref = :ref', ['ref' => 'K1/EA']));
        $this->assertFalse(entry::record_exists_select('externalref = :ref', ['ref' => 'K2/EA']));

        $rows = self::rows((int) auditor::run($source, '', (int) $this->staff->id)->id);
        $this->assertSame('existing', $rows['K1/EA']->outcome);
        $this->assertSame('skipped', $rows['K2/EA']->outcome);

        // Only an undecided conflict can be decided.
        $this->expectException(\moodle_exception::class);
        importer::decide((int) $rows['K2/EA']->id, 'import', (int) $this->staff->id);
    }

    /**
     * Members are matched as the setting says, and only to exactly one active user.
     */
    public function test_member_matching(): void {
        $this->getDataGenerator()->create_user(['idnumber' => 'dup']);
        $this->getDataGenerator()->create_user(['idnumber' => 'dup']);
        $source = self::csv([
            ['ANN@example.com', 'E1', '2026-03-10', '', '1', '', ''],
            ['dup', 'D1', '2026-03-10', '', '1', '', ''],
            ['1001', 'I1', '2026-03-10', '', '1', '', ''],
        ]);
        $rows = self::rows((int) auditor::run($source, '', 0)->id);
        $this->assertSame('nomember', $rows['E1/EA']->reason);
        $this->assertSame('nomember', $rows['D1/EA']->reason);
        $this->assertSame('new', $rows['I1/EA']->outcome);

        set_config('importmatch', 'email', 'local_cpdlog');
        $rows = self::rows((int) auditor::run($source, '', 0)->id);
        $this->assertSame('new', $rows['E1/EA']->outcome);
        $this->assertEquals($this->ann->id, $rows['E1/EA']->userid);
        $this->assertSame('nomember', $rows['I1/EA']->reason);
    }

    /**
     * Records of a member whose CPD data is being or was deleted are not imported.
     */
    public function test_deletion_guard(): void {
        global $DB;
        $source = self::csv([['1001', 'K1', '2026-03-10', '', '1', '', '']]);
        $run = auditor::run($source, '', (int) $this->staff->id);
        set_config('enabledeletion', 1, 'local_cpdlog');
        data_deleter::queue((int) $this->ann->id, (int) $this->staff->id);
        $this->assertSame('deleted', self::rows((int) auditor::run($source, '', 0)->id)['K1/EA']->reason);

        // A deletion queued after the audit still stops the import.
        $row = self::rows((int) $run->id)['K1/EA'];
        $this->assertFalse(importer::import_row($row, 'csv', (int) $this->staff->id));
        $this->assertFalse($DB->record_exists(entry::TABLE, ['externalref' => 'K1/EA']));
    }

    /**
     * Old audits are pruned, except records staff decided on.
     */
    public function test_prune(): void {
        global $DB;
        $this->getDataGenerator()->get_plugin_generator('local_cpdlog')->create_entry([
            'user' => $this->ann->username,
            'period' => '2026',
            'category' => 'EA',
            'day' => '10/03/2026',
            'hours' => 1,
            'status' => entry::STATUS_APPROVED,
        ]);
        $source = self::csv([
            ['1001', 'K1', '2026-03-10', '', '1', '', ''],
            ['1002', 'K2', '2026-03-10', '', '1', '', ''],
        ]);
        $old = auditor::run($source, '', 0);
        importer::decide((int) self::rows((int) $old->id)['K1/EA']->id, 'skip', (int) $this->staff->id);
        $older = auditor::run($source, '', 0);
        $DB->set_field(auditor::RUN_TABLE, 'timecreated', time() - (auditor::KEEP_DAYS + 1) * DAYSECS, []);

        auditor::run($source, '', 0);
        $this->assertTrue($DB->record_exists(auditor::RUN_TABLE, ['id' => $old->id]));
        $this->assertSame(['K1/EA'], array_keys(self::rows((int) $old->id)));
        $this->assertFalse($DB->record_exists(auditor::RUN_TABLE, ['id' => $older->id]));
        // The decided run and the new one are left.
        $this->assertSame(2, $DB->count_records(auditor::RUN_TABLE));
    }

    /**
     * A course completion released with its time of day still counts as the same day, and a
     * changed record whose hours no longer fit is unmatched rather than stopping the audit.
     */
    public function test_days_and_changed_hours(): void {
        global $DB;
        $generator = $this->getDataGenerator()->get_plugin_generator('local_cpdlog');
        $entry = $generator->create_entry([
            'user' => $this->ann->username,
            'period' => '2026',
            'category' => 'EA',
            'day' => '10/03/2026',
            'hours' => 2,
            'status' => entry::STATUS_APPROVED,
        ]);
        $DB->set_field(entry::TABLE, 'activitydate', self::day('2026-03-10') + 14 * HOURSECS, ['id' => $entry->get('id')]);
        $source = self::csv([
            ['1001', 'K1', '2026-03-10', 'Completed course', '2', '', ''],
            ['1002', 'K2', '2026-03-12', 'Workshop', '1', '', ''],
        ]);
        $run = auditor::run($source, '', (int) $this->staff->id);
        $this->assertSame('conflict', self::rows((int) $run->id)['K1/EA']->outcome);
        importer::queue((int) $run->id, (int) $this->staff->id);
        importer::import_run((int) $run->id);

        $source = self::csv([['1002', 'K2', '2026-03-12', 'Workshop', '10000', '', '']]);
        $rows = self::rows((int) auditor::run($source, '', (int) $this->staff->id)->id);
        $this->assertSame('badhours', $rows['K2/EA']->reason);
    }

    /**
     * A corrected title, provider or description is reported as changed; blank ones, imported as
     * defaults, are not.
     */
    public function test_changed_details(): void {
        // Here the Reviewing column holds the description and Other the provider.
        set_config('importmaphours', 'EA=EducationPoints', 'local_cpdlog');
        set_config('importmapdescription', 'Reviewing', 'local_cpdlog');
        set_config('importmapprovider', 'Other', 'local_cpdlog');
        $source = self::csv([['1002', 'K1', '2026-03-12', '', '1', 'Notes', '']]);
        $run = auditor::run($source, '', (int) $this->staff->id);
        importer::queue((int) $run->id, (int) $this->staff->id);
        importer::import_run((int) $run->id);
        $this->assertSame('existing', self::rows((int) auditor::run($source, '', 0)->id)['K1/EA']->outcome);

        $changes = [
            'title' => ['1002', 'K1', '2026-03-12', 'Corrected', '1', 'Notes', ''],
            'description' => ['1002', 'K1', '2026-03-12', '', '1', 'Better notes', ''],
            'provider' => ['1002', 'K1', '2026-03-12', '', '1', 'Notes', 'SCCA'],
        ];
        foreach ($changes as $field => $row) {
            $rows = self::rows((int) auditor::run(self::csv([$row]), '', 0)->id);
            $this->assertSame('changed', $rows['K1/EA']->outcome, $field);
        }
        $this->assertSame('Imported CPD', entry::get_record(['externalref' => 'K1/EA'])->get('activityname'));
    }

    /**
     * A key too long to keep whole with its category is unmatched rather than shortened, so two
     * categories never share one.
     */
    public function test_long_key(): void {
        $key = str_repeat('k', 254);
        $rows = self::rows((int) auditor::run(self::csv([['1001', $key, '2026-03-10', '', '1', '2', '']]), '', 0)->id);
        $this->assertCount(1, $rows);
        $this->assertSame('longkey', reset($rows)->reason);
    }

    /**
     * A busy member stops the import with an error to try again, without marking the run imported
     * or recording a decision.
     */
    public function test_busy_member(): void {
        global $CFG, $DB;
        // Database locks can be taken twice on one connection; file locks cannot.
        $CFG->lock_factory = '\\core\\lock\\file_lock_factory';
        $generator = $this->getDataGenerator()->get_plugin_generator('local_cpdlog');
        $generator->create_entry([
            'user' => $this->ann->username,
            'period' => '2026',
            'category' => 'EA',
            'day' => '10/03/2026',
            'hours' => 2,
            'status' => entry::STATUS_APPROVED,
        ]);
        $source = self::csv([
            ['1001', 'K1', '2026-03-10', '', '2', '', ''],
            ['1001', 'K2', '2026-03-11', '', '1', '', ''],
        ]);
        $run = auditor::run($source, '', (int) $this->staff->id);
        $rows = self::rows((int) $run->id);
        importer::queue((int) $run->id, (int) $this->staff->id);

        $lock = \core\lock\lock_config::get_lock_factory(data_deleter::LOCK_TYPE)
            ->get_lock(data_deleter::lock_key((int) $this->ann->id), 1);
        try {
            $calls = [
                fn() => importer::import_run((int) $run->id),
                fn() => importer::decide((int) $rows['K1/EA']->id, 'import', 2),
            ];
            foreach ($calls as $call) {
                try {
                    $call();
                    $this->fail('Expected an exception');
                } catch (\moodle_exception $e) {
                    $this->assertSame('import:locked', $e->errorcode);
                }
            }
        } finally {
            $lock->release();
        }
        $this->assertSame('queued', $this->get_run((int) $run->id)->status);
        $this->assertNull($DB->get_field(auditor::ROW_TABLE, 'decision', ['id' => $rows['K1/EA']->id]));
        $this->assertSame(1, importer::import_run((int) $run->id));
    }

    /**
     * Open conflicts are each record's latest audit, so a later one-member audit or another file
     * does not hide other members' conflicts, and a record audited again shows once.
     */
    public function test_open_conflicts(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_cpdlog');
        foreach ([$this->ann, $this->ben] as $user) {
            $generator->create_entry([
                'user' => $user->username,
                'period' => '2026',
                'category' => 'EA',
                'day' => '10/03/2026',
                'hours' => 2,
                'status' => entry::STATUS_APPROVED,
            ]);
        }
        $source = self::csv([
            ['1001', 'A1', '2026-03-10', '', '2', '', ''],
            ['1002', 'B1', '2026-03-10', '', '2', '', ''],
        ]);
        auditor::run($source, '', 0);
        $latest = auditor::run($source, '1001', 0);
        $open = importer::get_open_conflicts();
        $this->assertSame(['A1/EA', 'B1/EA'], array_values(array_column($open, 'rowkey')));
        $this->assertEquals($latest->id, reset($open)->runid);

        importer::decide((int) reset($open)->id, 'skip', (int) $this->staff->id);
        $this->assertSame(['B1/EA'], array_values(array_column(importer::get_open_conflicts(), 'rowkey')));
    }
}
