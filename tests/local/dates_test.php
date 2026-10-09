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
 * Tests for the calendar-day helpers.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
     * Whole days are counted across a daylight-saving change, whatever the time of day.
     */
    public function test_days_between(): void {
        $this->resetAfterTest();
        $this->setTimezone('Australia/Sydney');

        $this->assertSame(2, dates::days_between(self::sydney('2026-10-03 23:30'), self::sydney('2026-10-05 00:10')));
        $this->assertSame(1, dates::days_between(self::sydney('2026-04-05 01:00'), self::sydney('2026-04-06 00:00')));
        $this->assertSame(0, dates::days_between(self::sydney('2026-12-31 00:00'), self::sydney('2026-12-31 23:59')));
        $this->assertSame(-14, dates::days_between(self::sydney('2026-12-31 09:00'), self::sydney('2026-12-17 09:00')));
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
