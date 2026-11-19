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
 * Scheduled task reading upcoming events from iMIS.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\task;

use local_cpdlog\local\imis\connection;
use local_cpdlog\local\upcoming;
use local_cpdlog\local\upcoming\imis_source;

/**
 * Scheduled task keeping the list of upcoming iMIS events fresh, so pages never wait on iMIS for it.
 *
 * Does nothing unless upcoming CPD is shown, iMIS is one of its sources and the connection is set.
 */
class refresh_imis_events extends \core\task\scheduled_task
{
    /**
     * Returns the task name shown to administrators.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:refreshimisevents', 'local_cpdlog');
    }

    /**
     * Reads the events and their CPD hours from iMIS. A failure is left for the task to retry.
     */
    public function execute(): void {
        if (!upcoming::is_enabled() || upcoming::get_source_setting() === upcoming::SOURCE_MOODLE) {
            return;
        }
        $connection = connection::get();
        if (!$connection->is_configured()) {
            mtrace(get_string('imis:notconfigured', 'local_cpdlog'));
            return;
        }
        $cached = imis_source::refresh($connection);
        mtrace(get_string('imis:refreshed', 'local_cpdlog', count($cached->events)));
    }
}
