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
 * Where upcoming CPD comes from.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local\upcoming;

/**
 * A source of upcoming CPD: Moodle now, iMIS later (decision 8).
 *
 * Each item is a \stdClass with:
 * - type: 'course' (a course that awards CPD starting) or 'event' (a calendar event);
 * - title: plain text, not escaped;
 * - coursename: plain text, or '' when the item belongs to no course;
 * - timestart: when it starts;
 * - allday: whether only the date matters;
 * - hours: the CPD hours as text, or '' when not known;
 * - category: the CPD category name, plain text, or '';
 * - url: where to find out more and enrol;
 * - mine: whether the member is already taking part (for Moodle, enrolled in the course).
 */
interface source
{
    /**
     * Returns the upcoming CPD a member can see, starting from $from and before $to, soonest first.
     *
     * @param int $userid The member.
     * @param int $from The start of the window.
     * @param int $to The end of the window.
     * @return \stdClass[]
     */
    public function get_items(int $userid, int $from, int $to): array;
}
