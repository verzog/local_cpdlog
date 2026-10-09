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
 * Calendar-day helpers for CPD period boundaries.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local;

/**
 * Calendar-day helpers for CPD period boundaries.
 *
 * Period boundaries are whole days in the site timezone. Days are stepped with calendar arithmetic,
 * never by adding DAYSECS, because days in Australia/Sydney are 23 or 25 hours long at DST changes.
 */
final class dates
{
    /**
     * Returns the start of the day after the day containing a timestamp.
     *
     * @param int $timestamp Any moment in the day.
     * @param \DateTimeZone|null $timezone Timezone of the day; the site timezone if null.
     * @return int Timestamp of midnight at the start of the following day.
     */
    public static function next_day_start(int $timestamp, ?\DateTimeZone $timezone = null): int {
        return self::day_start($timestamp, '+1 day', $timezone);
    }

    /**
     * Returns the start of the day before the day containing a timestamp.
     *
     * @param int $timestamp Any moment in the day.
     * @param \DateTimeZone|null $timezone Timezone of the day; the site timezone if null.
     * @return int Timestamp of midnight at the start of the previous day.
     */
    public static function previous_day_start(int $timestamp, ?\DateTimeZone $timezone = null): int {
        return self::day_start($timestamp, '-1 day', $timezone);
    }

    /**
     * Returns how many calendar days lie between the days containing two timestamps.
     *
     * Counts whole days in the given timezone, so a daylight-saving change in between does not
     * shorten or lengthen the count.
     *
     * @param int $from Any moment in the first day.
     * @param int $to Any moment in the second day.
     * @param \DateTimeZone|null $timezone Timezone of the days; the site timezone if null.
     * @return int Days from the first day to the second; negative if the second is earlier.
     */
    public static function days_between(int $from, int $to, ?\DateTimeZone $timezone = null): int {
        $timezone = $timezone ?? \core_date::get_server_timezone_object();
        $start = (new \DateTimeImmutable('@' . $from))->setTimezone($timezone)->setTime(0, 0);
        $end = (new \DateTimeImmutable('@' . $to))->setTimezone($timezone)->setTime(0, 0);
        $diff = $start->diff($end);
        return $diff->invert ? -$diff->days : $diff->days;
    }

    /**
     * Returns the start of a day relative to the day containing a timestamp.
     *
     * @param int $timestamp Any moment in the day.
     * @param string $modifier A DateTime modifier such as '+1 day'.
     * @param \DateTimeZone|null $timezone Timezone of the day; the site timezone if null.
     * @return int Timestamp of midnight at the start of the resulting day.
     */
    private static function day_start(int $timestamp, string $modifier, ?\DateTimeZone $timezone): int {
        $timezone = $timezone ?? \core_date::get_server_timezone_object();
        $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
        return $date->modify($modifier)->setTime(0, 0)->getTimestamp();
    }
}
