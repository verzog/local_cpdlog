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
 * Tests for the calendar-day helpers.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\local;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the calendar-day helpers.
 */
#[CoversClass(dates::class)]
final class dates_test extends \advanced_testcase
{
    /**
     * Returns a timestamp for a local time in Sydney.
     *
     * @param string $datetime Local date and time.
     * @return int
     */
    private static function sydney(string $datetime): int {
        return (new \DateTimeImmutable($datetime, new \DateTimeZone('Australia/Sydney')))->getTimestamp();
    }

    /**
     * The day DST starts in Sydney is 23 hours long, and the next day still starts at midnight.
     */
    public function test_next_day_start_across_dst_start(): void {
        $this->resetAfterTest();
        $this->setTimezone('Australia/Sydney');

        $next = dates::next_day_start(self::sydney('2026-10-04 00:00'));

        $this->assertSame(self::sydney('2026-10-05 00:00'), $next);
        $this->assertSame(23 * HOURSECS, $next - self::sydney('2026-10-04 00:00'));
    }

    /**
     * The day DST ends in Sydney is 25 hours long, and the next day still starts at midnight.
     */
    public function test_next_day_start_across_dst_end(): void {
        $this->resetAfterTest();
        $this->setTimezone('Australia/Sydney');

        $next = dates::next_day_start(self::sydney('2026-04-05 00:00'));

        $this->assertSame(self::sydney('2026-04-06 00:00'), $next);
        $this->assertSame(25 * HOURSECS, $next - self::sydney('2026-04-05 00:00'));
    }

    /**
     * Any moment in a day gives the same next and previous day starts.
     */
    public function test_day_starts_ignore_time_of_day(): void {
        $this->resetAfterTest();
        $this->setTimezone('Australia/Sydney');

        $this->assertSame(self::sydney('2026-12-31 00:00'), dates::next_day_start(self::sydney('2026-12-30 23:59')));
        $this->assertSame(self::sydney('2027-01-01 00:00'), dates::next_day_start(self::sydney('2026-12-31 12:00')));
        $this->assertSame(self::sydney('2026-10-04 00:00'), dates::previous_day_start(self::sydney('2026-10-05 00:00')));
    }

    /**
     * An explicit timezone overrides the site timezone.
     */
    public function test_explicit_timezone(): void {
        $this->resetAfterTest();
        $this->setTimezone('Australia/Sydney');
        $perth = new \DateTimeZone('Australia/Perth');

        $expected = (new \DateTimeImmutable('2026-06-02 00:00', $perth))->getTimestamp();
        $this->assertSame($expected, dates::next_day_start($expected - HOURSECS, $perth));
    }
}
