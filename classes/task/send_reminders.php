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
 * Scheduled task reminding members who are behind on CPD targets before a period closes.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\task;

use local_cpdlog\local\reminder;

/**
 * Scheduled task reminding members who are behind on CPD targets before a period closes.
 *
 * Runs daily. Does nothing while reminders are switched off; see local_cpdlog\local\reminder.
 */
class send_reminders extends \core\task\scheduled_task
{
    /**
     * Returns the task name shown to administrators.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:sendreminders', 'local_cpdlog');
    }

    /**
     * Sends the reminders due today.
     */
    public function execute(): void {
        if (!reminder::is_enabled()) {
            return;
        }
        mtrace(get_string('reminders:sent', 'local_cpdlog', reminder::send_due()));
    }
}
