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
 * Event observers of the CPD logbook.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog;

use local_cpdlog\local\course_cpd;

/**
 * Event observers of the CPD logbook.
 */
class observer
{
    /**
     * Creates a CPD entry, for approval, when a member completes a course that awards CPD.
     *
     * @param \core\event\course_completed $event The completion.
     */
    public static function course_completed(\core\event\course_completed $event): void {
        global $DB;
        $timecompleted = $DB->get_field('course_completions', 'timecompleted', ['id' => $event->objectid]);
        if ($timecompleted) {
            course_cpd::award((int) $event->relateduserid, (int) $event->courseid, (int) $timecompleted, true);
        }
    }
}
