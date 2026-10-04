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
 * Scheduled task copying CPD hours awarded by the image blog into the logbook.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\task;

use local_cpdlog\local\imageblog_sync;

/**
 * Scheduled task copying CPD hours awarded by the image blog into the logbook.
 *
 * Does nothing unless the image blog is installed and copying is switched on. The first run also
 * copies every award made before the logbook was connected.
 */
class sync_imageblog extends \core\task\scheduled_task
{
    /**
     * Returns the task name shown to administrators.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:syncimageblog', 'local_cpdlog');
    }

    /**
     * Copies new and changed awards and reverses withdrawn ones.
     */
    public function execute(): void {
        if (!imageblog_sync::is_enabled()) {
            return;
        }
        $result = imageblog_sync::sync();
        mtrace(get_string('imageblog:synced', 'local_cpdlog', $result));
    }
}
