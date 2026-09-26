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
 * Tests for the CPD entries report source, the starting reports and the CPD staff audience.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\reportbuilder\datasource;

use core_reportbuilder\local\helpers\audience;
use core_reportbuilder\local\models\report as report_model;
use core_reportbuilder\manager;
use core_reportbuilder\tests\core_reportbuilder_testcase;
use local_cpdlog\local\setup;
use local_cpdlog\persistent\entry;
use local_cpdlog\reportbuilder\audience\cpdstaff;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the CPD entries report source, the starting reports and the CPD staff audience.
 */
#[CoversClass(entries::class)]
#[CoversClass(\local_cpdlog\reportbuilder\local\entities\entry::class)]
#[CoversClass(\local_cpdlog\reportbuilder\local\entities\category::class)]
#[CoversClass(\local_cpdlog\reportbuilder\local\entities\period::class)]
#[CoversClass(cpdstaff::class)]
#[CoversClass(setup::class)]
final class entries_test extends core_reportbuilder_testcase
{
    /**
     * Sets up a period and two members with entries.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Australia/Sydney');
        $generator = $this->getDataGenerator()->get_plugin_generator('local_cpdlog');
        $generator->create_period(['name' => '2026', 'firstday' => '01/01/2026', 'lastday' => '31/12/2026']);
        $this->getDataGenerator()->create_course(['fullname' => 'Dermoscopy basics', 'shortname' => 'DERM']);
        foreach (['alice' => 'Alice', 'bruce' => 'Bruce'] as $username => $firstname) {
            $user = $this->getDataGenerator()->create_user([
                'username' => $username,
                'firstname' => $firstname,
                'lastname' => 'Member',
            ]);
            $generator->create_entry([
                'userid' => $user->id,
                'period' => '2026',
                'course' => 'DERM',
                'day' => '10/03/2026',
                'category' => 'RP',
                'hours' => 1.5,
                'status' => entry::STATUS_APPROVED,
            ]);
        }
    }

    /**
     * A report with the default setup lists each entry with its member, date, category, course, hours and status.
     */
    public function test_default_report(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'Entries', 'source' => entries::class, 'default' => 1]);

        $content = $this->get_custom_report_content((int) $report->get('id'));
        $this->assertCount(2, $content);
        $row = array_values($content[0]);
        $this->assertStringContainsString('Member', $row[0]);
        $this->assertSame(['10/03/2026', 'Reviewing performance', 'Dermoscopy basics', '1.50', 'Approved'], array_slice($row, 1));
    }

    /**
     * Custom user profile fields can filter the report, through the core user entity.
     */
    public function test_profile_field_filter(): void {
        $this->getDataGenerator()->create_custom_profile_field([
            'shortname' => 'membership',
            'name' => 'Membership type',
            'datatype' => 'text',
        ]);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'Entries', 'source' => entries::class, 'default' => 0]);

        $filters = manager::get_report_from_persistent($report)->get_filters();
        $this->assertArrayHasKey('user:profilefield_membership', $filters);
        $this->assertArrayHasKey('member:cohort', $filters);
    }

    /**
     * Every column, aggregation and condition of the source produces a working report.
     */
    public function test_stress_datasource(): void {
        $this->datasource_stress_test_columns(entries::class);
        $this->datasource_stress_test_columns_aggregation(entries::class);
        $this->datasource_stress_test_conditions(entries::class, 'entry:hours');
    }

    /**
     * Installing adds one starting report per source, shown only to CPD staff, and running it again adds none.
     */
    public function test_default_reports_and_audience(): void {
        $params = ['type' => \core_reportbuilder\datasource::TYPE_CUSTOM_REPORT];
        $reports = [];
        foreach (report_model::get_records($params) as $report) {
            $reports[$report->get('source')] = $report;
        }
        $this->assertArrayHasKey(entries::class, $reports);
        $this->assertArrayHasKey(progress::class, $reports);

        setup::add_default_reports();
        $this->assertCount(2, report_model::get_records($params));

        $staff = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/cpdlog:viewall', CAP_ALLOW, $roleid, \context_system::instance());
        $this->getDataGenerator()->role_assign($roleid, $staff->id);
        $member = $this->getDataGenerator()->create_user();
        audience::purge_caches();

        $progressid = (int) $reports[progress::class]->get('id');
        $this->assertContains($progressid, array_map('intval', audience::user_reports_list((int) $staff->id)));
        $this->assertNotContains($progressid, array_map('intval', audience::user_reports_list((int) $member->id)));
    }
}
