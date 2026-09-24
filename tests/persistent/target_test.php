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
 * Tests for the CPD target persistent.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\persistent;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the CPD target persistent.
 */
#[CoversClass(target::class)]
final class target_test extends \advanced_testcase
{
    /**
     * Creates a 2026 period.
     *
     * @return int The period id.
     */
    private static function create_period(): int {
        $period = new period(0, (object) ['name' => '2026', 'startdate' => 1767186000, 'enddate' => 1798722000]);
        return (int) $period->create()->get('id');
    }

    /**
     * Builds an unsaved target.
     *
     * @param int $periodid Period id.
     * @param float $hours Required hours.
     * @param int|null $cohortid Cohort id, or null for all members.
     * @return target
     */
    private static function make_target(int $periodid, float $hours, ?int $cohortid = null): target {
        $record = ['periodid' => $periodid, 'cohortid' => $cohortid, 'name' => 'Target', 'requiredhours' => $hours];
        return new target(0, (object) $record);
    }

    /**
     * Returns a category id by short name.
     *
     * @param string $shortname Short name.
     * @return int
     */
    private static function categoryid(string $shortname): int {
        return (int) category::get_record(['shortname' => $shortname])->get('id');
    }

    /**
     * Required hours must be positive and fit the column.
     */
    public function test_requiredhours_bounds(): void {
        $this->resetAfterTest();
        $periodid = self::create_period();

        $this->assertArrayHasKey('requiredhours', self::make_target($periodid, 0)->get_errors());
        $this->assertArrayHasKey('requiredhours', self::make_target($periodid, -5)->get_errors());
        $this->assertArrayHasKey('requiredhours', self::make_target($periodid, 1000000)->get_errors());
        $this->assertTrue(self::make_target($periodid, 12.5)->is_valid());
    }

    /**
     * The period and cohort must exist; no cohort means all members.
     */
    public function test_period_and_cohort_must_exist(): void {
        $this->resetAfterTest();
        $periodid = self::create_period();
        $cohort = $this->getDataGenerator()->create_cohort();

        $noperiod = self::make_target($periodid + 1, 50);
        $this->assertArrayHasKey('periodid', $noperiod->get_errors());

        $nocohort = self::make_target($periodid, 50, $cohort->id + 1);
        $this->assertArrayHasKey('cohortid', $nocohort->get_errors());

        $withcohort = self::make_target($periodid, 50, $cohort->id);
        $this->assertTrue($withcohort->is_valid());

        $allmembers = self::make_target($periodid, 50);
        $this->assertTrue($allmembers->is_valid());
        $this->assertNull($allmembers->get('cohortid'));
    }

    /**
     * Setting categories replaces the previous set, and none means every category counts.
     */
    public function test_set_categoryids_replaces(): void {
        $this->resetAfterTest();
        $target = self::make_target(self::create_period(), 25);
        $target->create();

        $target->set_categoryids([self::categoryid('RP'), self::categoryid('MO'), self::categoryid('RP')]);
        $this->assertEqualsCanonicalizing([self::categoryid('RP'), self::categoryid('MO')], $target->get_categoryids());

        $target->set_categoryids([self::categoryid('EA')]);
        $this->assertSame([self::categoryid('EA')], $target->get_categoryids());

        $target->set_categoryids([]);
        $this->assertSame([], $target->get_categoryids());
    }

    /**
     * An unknown category is rejected without changing the existing links.
     */
    public function test_set_categoryids_rejects_unknown(): void {
        $this->resetAfterTest();
        $target = self::make_target(self::create_period(), 12.5);
        $target->create();
        $target->set_categoryids([self::categoryid('EA')]);

        try {
            $target->set_categoryids([self::categoryid('EA'), 999999]);
            $this->fail('Expected an exception for an unknown category.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertSame([self::categoryid('EA')], $target->get_categoryids());
        }
    }

    /**
     * Categories cannot be set before the target is saved.
     */
    public function test_set_categoryids_requires_saved_target(): void {
        $this->expectException(\coding_exception::class);
        (new target(0, (object) ['name' => 'Unsaved', 'requiredhours' => 1]))->set_categoryids([]);
    }

    /**
     * Deleting a target removes its category links.
     */
    public function test_delete_removes_links(): void {
        global $DB;
        $this->resetAfterTest();
        $target = self::make_target(self::create_period(), 12.5);
        $target->create();
        $target->set_categoryids([self::categoryid('EA')]);
        $targetid = $target->get('id');

        $target->delete();

        $this->assertFalse($DB->record_exists(target::CATEGORY_TABLE, ['targetid' => $targetid]));
    }
}
