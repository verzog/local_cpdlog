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
 * Scheduled task creating CPD entries for past and missed course completions.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\task;

use local_cpdlog\local\course_cpd;

/**
 * Scheduled task creating CPD entries for past and missed course completions.
 *
 * Completions normally create their entry at once; this task catches those made before a course
 * awarded CPD or before the logbook was installed, and any the completion event missed.
 */
class award_course_cpd extends \core\task\scheduled_task
{
    /**
     * Returns the task name shown to administrators.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:awardcoursecpd', 'local_cpdlog');
    }

    /**
     * Creates the entries due.
     */
    public function execute(): void {
        if (!course_cpd::is_enabled()) {
            return;
        }
        mtrace(get_string('coursecompleted:caughtup', 'local_cpdlog', course_cpd::catch_up()));
    }
}
