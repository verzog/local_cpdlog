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
 * Scheduled task telling approvers about course completions waiting to be released.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\task;

use local_cpdlog\local\course_cpd;

/**
 * Scheduled task telling approvers about course completions waiting to be released.
 *
 * Runs daily, and only sends a notice when new completions have arrived since the last one.
 */
class notify_completions_waiting extends \core\task\scheduled_task
{
    /**
     * Returns the task name shown to administrators.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:notifycompletions', 'local_cpdlog');
    }

    /**
     * Sends the notice if there is anything new.
     */
    public function execute(): void {
        if (!course_cpd::is_enabled()) {
            return;
        }
        mtrace(get_string('release:noticesent', 'local_cpdlog', course_cpd::notify_waiting()));
    }
}
