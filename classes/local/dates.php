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
 * Calendar-day helpers for CPD period boundaries.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
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
