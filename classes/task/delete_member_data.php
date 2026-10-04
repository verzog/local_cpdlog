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
 * Adhoc task that carries out a queued deletion of a member's CPD data.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\task;

use local_cpdlog\local\data_deleter;

/**
 * Adhoc task that carries out a queued deletion of a member's CPD data.
 *
 * Custom data: deletionid, the register id. If the task fails, the deletion stays queued and Moodle
 * retries the task later.
 */
class delete_member_data extends \core\task\adhoc_task
{
    /**
     * Returns the task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:deletememberdata', 'local_cpdlog');
    }

    /**
     * Carries out the deletion.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        data_deleter::run((int) ($data->deletionid ?? 0));
    }
}
