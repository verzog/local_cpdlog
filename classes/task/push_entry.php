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
 * Adhoc task sending one CPD entry to iMIS.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\task;

use local_cpdlog\local\push\pusher;

/**
 * Sends one approved or reversed entry to iMIS. A failure is thrown so the task is retried.
 *
 * Custom data: entryid.
 */
class push_entry extends \core\task\adhoc_task
{
    /**
     * Sends the entry.
     */
    public function execute(): void {
        $entryid = (int) $this->get_custom_data()->entryid;
        $status = pusher::send($entryid);
        mtrace(get_string('push:done', 'local_cpdlog', (object) ['entryid' => $entryid, 'status' => $status]));
    }
}
