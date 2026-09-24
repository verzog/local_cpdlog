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
 * Tests for the CPD period persistent.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\persistent;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the CPD period persistent.
 */
#[CoversClass(period::class)]
final class period_test extends \advanced_testcase
{
    /**
     * Returns midnight at the start of a day in Sydney.
     *
     * @param string $date Local date.
     * @return int
     */
    private static function day(string $date): int {
        return (new \DateTimeImmutable($date, new \DateTimeZone('Australia/Sydney')))->getTimestamp();
    }

    /**
     * Builds an unsaved period.
     *
     * @param string $name Name.
     * @param string $first First day.
     * @param string $after Day after the last day.
     * @return period
     */
    private static function make_period(string $name, string $first, string $after): period {
        return new period(0, (object) ['name' => $name, 'startdate' => self::day($first), 'enddate' => self::day($after)]);
    }

    /**
     * Creates a period.
     *
     * @param string $name Name.
     * @param string $first First day.
     * @param string $after Day after the last day.
     * @return period
     */
    private static function create_period(string $name, string $first, string $after): period {
        return self::make_period($name, $first, $after)->create();
    }

    /**
     * A period must end after it starts.
     */
    public function test_enddate_after_startdate(): void {
        $period = self::make_period('2026', '2026-01-01', '2026-01-01');

        $this->assertArrayHasKey('enddate', $period->get_errors());
    }

    /**
     * Overlapping periods are rejected; adjacent periods are allowed.
     */
    public function test_overlap(): void {
        $this->resetAfterTest();
        $this->setTimezone('Australia/Sydney');
        self::create_period('2026', '2026-01-01', '2027-01-01');

        $overlapping = self::make_period('Late 2026', '2026-12-31', '2027-06-01');
        $this->assertArrayHasKey('enddate', $overlapping->get_errors());

        $inside = self::make_period('Mid 2026', '2026-03-01', '2026-04-01');
        $this->assertArrayHasKey('enddate', $inside->get_errors());

        $adjacent = self::make_period('2027', '2027-01-01', '2028-01-01');
        $this->assertTrue($adjacent->is_valid());
    }

    /**
     * Editing a period does not count as overlapping itself.
     */
    public function test_edit_does_not_overlap_itself(): void {
        $this->resetAfterTest();
        $period = self::create_period('2026', '2026-01-01', '2027-01-01');

        $period->set('name', 'Calendar year 2026');
        $this->assertTrue($period->is_valid());
    }

    /**
     * The last day is the day before the exclusive end date, even across a DST change.
     */
    public function test_get_lastday(): void {
        $this->resetAfterTest();
        $this->setTimezone('Australia/Sydney');
        $period = self::create_period('To DST', '2026-07-01', '2026-10-05');

        $this->assertSame(self::day('2026-10-04'), $period->get_lastday());
    }

    /**
     * A period can be deleted only while no target, entry or cohort choice refers to it.
     */
    public function test_can_delete(): void {
        $this->resetAfterTest();
        $period = self::create_period('2026', '2026-01-01', '2027-01-01');
        $this->assertTrue($period->can_delete());

        (new target(0, (object) ['periodid' => $period->get('id'), 'name' => 'Total', 'requiredhours' => 50]))->create();
        $this->assertFalse($period->can_delete());
    }

    /**
     * Closing a period is reported by is_closed.
     */
    public function test_is_closed(): void {
        $this->resetAfterTest();
        $period = self::create_period('2026', '2026-01-01', '2027-01-01');
        $this->assertFalse($period->is_closed());

        $period->set('status', period::STATUS_CLOSED);
        $period->update();
        $this->assertTrue((new period($period->get('id')))->is_closed());
    }
}
