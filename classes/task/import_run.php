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
 * Adhoc task importing an audited run of CPD history.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\task;

use local_cpdlog\local\import\importer;

/**
 * Imports the new rows of an audit run that staff chose to import.
 *
 * Custom data: runid.
 */
class import_run extends \core\task\adhoc_task
{
    /**
     * Imports the run's new rows.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        mtrace(get_string('import:importdone', 'local_cpdlog', importer::import_run((int) $data->runid)));
    }
}
