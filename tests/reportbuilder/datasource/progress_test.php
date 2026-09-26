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
 * Tests for the CPD progress report source.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\reportbuilder\datasource;

use core_reportbuilder\tests\core_reportbuilder_testcase;
use local_cpdlog\local\progress as progress_calculator;
use local_cpdlog\local\target_resolver;
use local_cpdlog\persistent\entry;
use local_cpdlog\persistent\period;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the CPD progress report source.
 */
#[CoversClass(progress::class)]
#[CoversClass(\local_cpdlog\reportbuilder\local\entities\target_progress::class)]
#[CoversClass(\local_cpdlog\reportbuilder\local\entities\member::class)]
#[CoversClass(\local_cpdlog\reportbuilder\local\filters\member_cohort::class)]
final class progress_test extends core_reportbuilder_testcase
{
    /** @var period The 2026 period. */
    private period $period;

    /** @var \stdClass[] Users keyed by username. */
    private array $users = [];

    /** @var \stdClass[] Cohorts keyed by idnumber. */
    private array $cohorts = [];

    /**
     * Sets up targets, cohorts and members covering each way a target can apply.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Australia/Sydney');
        $generator = $this->getDataGenerator()->get_plugin_generator('local_cpdlog');
        $this->period = $generator->create_period(['name' => '2026', 'firstday' => '01/01/2026', 'lastday' => '31/12/2026']);
        $generator->create_period(['name' => '2025', 'firstday' => '01/01/2025', 'lastday' => '31/12/2025']);

        foreach (['cohorta', 'cohortb'] as $idnumber) {
            $this->cohorts[$idnumber] = $this->getDataGenerator()->create_cohort(['idnumber' => $idnumber]);
        }
        $generator->create_target(['period' => '2026', 'name' => 'Total', 'requiredhours' => 10, 'sortorder' => 1]);
        $generator->create_target([
            'period' => '2026',
            'name' => 'Reviewing',
            'requiredhours' => 2,
            'categories' => 'RP',
            'sortorder' => 2,
        ]);
        $generator->create_target(['period' => '2026', 'cohort' => 'cohorta', 'name' => 'Cohort A', 'requiredhours' => 3]);
        $generator->create_target(['period' => '2026', 'cohort' => 'cohortb', 'name' => 'Cohort B', 'requiredhours' => 4]);

        // Each user covers one case: no cohort; cohort A; both cohorts unresolved; both with B chosen;
        // cohort A with an out-of-date choice of none; cohort A with no entries; no CPD at all; deleted.
        $cases = [
            'nocohort' => [],
            'onlya' => ['cohorta'],
            'conflict' => ['cohorta', 'cohortb'],
            'choseb' => ['cohorta', 'cohortb'],
            'stalechoice' => ['cohorta'],
            'noentries' => ['cohorta'],
            'nothing' => [],
            'deleted' => [],
        ];
        foreach ($cases as $username => $cohorts) {
            $user = $this->getDataGenerator()->create_user(['username' => $username]);
            $this->users[$username] = $user;
            foreach ($cohorts as $idnumber) {
                cohort_add_member($this->cohorts[$idnumber]->id, $user->id);
            }
            if (in_array($username, ['noentries', 'nothing'], true)) {
                continue;
            }
            $entries = [
                ['category' => 'RP', 'hours' => 2.5, 'status' => entry::STATUS_APPROVED],
                ['category' => 'EA', 'hours' => 1, 'status' => entry::STATUS_SUBMITTED],
                ['category' => 'EA', 'hours' => 8, 'status' => entry::STATUS_REVERSED],
            ];
            foreach ($entries as $entry) {
                $generator->create_entry($entry + ['userid' => $user->id, 'period' => '2026']);
            }
        }
        $admin = get_admin();
        target_resolver::set_choice(
            (int) $this->users['choseb']->id,
            $this->period->get('id'),
            (int) $this->cohorts['cohortb']->id,
            (int) $admin->id
        );
        // A choice of none, recorded while the member was in both cohorts, no longer applies once they leave one.
        cohort_add_member($this->cohorts['cohortb']->id, $this->users['stalechoice']->id);
        $staleid = (int) $this->users['stalechoice']->id;
        target_resolver::set_choice($staleid, $this->period->get('id'), null, (int) $admin->id);
        cohort_remove_member($this->cohorts['cohortb']->id, $this->users['stalechoice']->id);
        delete_user($this->users['deleted']);
    }

    /**
     * Creates a progress report with the columns the tests read.
     *
     * @return int The report id.
     */
    private function create_report(): int {
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'Progress', 'source' => progress::class, 'default' => 0]);
        $columns = [
            'user:username',
            'target_progress:name',
            'target_progress:approvedhours',
            'target_progress:pendinghours',
            'target_progress:met',
            'target_progress:percent',
        ];
        foreach ($columns as $column) {
            $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => $column]);
        }
        return (int) $report->get('id');
    }

    /**
     * Returns report rows as "username|target|approved|pending|met|percent" strings, sorted.
     *
     * @param int $reportid The report.
     * @param array $filters Filter values.
     * @return string[]
     */
    private function rows(int $reportid, array $filters = []): array {
        $content = $this->get_custom_report_content($reportid, 100, $filters);
        $rows = array_map(fn($row) => implode('|', array_values($row)), $content);
        sort($rows);
        return $rows;
    }

    /**
     * The report lists every member's progress exactly as the member sees it, for each target that applies.
     */
    public function test_matches_member_progress(): void {
        $expected = [];
        foreach ($this->users as $username => $user) {
            if (in_array($username, ['nothing', 'deleted'], true)) {
                continue;
            }
            foreach (progress_calculator::get_progress((int) $user->id, $this->period->get('id')) as $row) {
                $expected[] = implode('|', [
                    $username,
                    $row->target->get('name'),
                    format_float($row->approved, 2),
                    format_float($row->pending, 2),
                    $row->met ? get_string('yes') : get_string('no'),
                    get_string('percents', 'moodle', $row->percent),
                ]);
            }
        }
        sort($expected);

        $this->assertSame($expected, $this->rows($this->create_report()));
        // Spot-check the rules the expectation relies on.
        $this->assertContains('onlya|Cohort A|2.50|1.00|No|83%', $expected);
        $this->assertContains('choseb|Cohort B|2.50|1.00|No|62%', $expected);
        $this->assertContains('stalechoice|Cohort A|2.50|1.00|No|83%', $expected);
        $this->assertContains('noentries|Cohort A|0.00|0.00|No|0%', $expected);
        $this->assertContains('nocohort|Reviewing|2.50|0.00|Yes|100%', $expected);
        $this->assertEmpty(preg_grep('/^conflict\|Cohort/', $expected));
        $this->assertEmpty(preg_grep('/^(nothing|deleted)\|/', $expected));
    }

    /**
     * The cohort filter keeps members of any chosen cohort, once each.
     */
    public function test_cohort_filter(): void {
        $reportid = $this->create_report();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $generator->create_filter(['reportid' => $reportid, 'uniqueidentifier' => 'member:cohort']);

        $values = ['member:cohort_values' => [$this->cohorts['cohorta']->id, $this->cohorts['cohortb']->id]];
        $usernames = array_unique(array_map(fn($row) => explode('|', $row)[0], $this->rows($reportid, $values)));
        sort($usernames);
        $this->assertSame(['choseb', 'conflict', 'noentries', 'onlya', 'stalechoice'], array_values($usernames));

        // A member of both cohorts appears once per target, not once per cohort.
        $conflictrows = preg_grep('/^conflict\|/', $this->rows($reportid, $values));
        $this->assertCount(2, $conflictrows);
    }

    /**
     * Every column, aggregation and condition of the source produces a working report.
     */
    public function test_stress_datasource(): void {
        $this->datasource_stress_test_columns(progress::class);
        $this->datasource_stress_test_columns_aggregation(progress::class);
        $this->datasource_stress_test_conditions(progress::class, 'target_progress:name');
    }
}
