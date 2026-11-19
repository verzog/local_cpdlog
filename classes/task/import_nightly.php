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
 * Scheduled task auditing, and if allowed importing, CPD history from iMIS.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\task;

use local_cpdlog\local\import\auditor;
use local_cpdlog\local\import\imis_iqa_source;
use local_cpdlog\local\import\importer;

/**
 * Nightly audit of CPD history in iMIS for every member.
 *
 * Off by default (_Nightly import_). While _Dry run_ is on (the default) it only audits, so staff can
 * check the results first; otherwise it imports the new rows too. Conflicts always wait for staff.
 */
class import_nightly extends \core\task\scheduled_task
{
    /**
     * Returns the task name shown to administrators.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:importnightly', 'local_cpdlog');
    }

    /**
     * Audits iMIS and imports the new rows unless in dry run. A failure is left for the task to retry.
     */
    public function execute(): void {
        if (!get_config('local_cpdlog', 'importnightly')) {
            return;
        }
        $source = new imis_iqa_source();
        if (!$source->is_configured()) {
            mtrace(get_string('import:notconfigured', 'local_cpdlog'));
            return;
        }
        $run = auditor::run($source, '', 0);
        mtrace(get_string('import:audited', 'local_cpdlog', (object) json_decode($run->counts)));
        $dryrun = get_config('local_cpdlog', 'importdryrun');
        if ($dryrun === false || $dryrun) {
            mtrace(get_string('import:dryrun', 'local_cpdlog'));
            return;
        }
        mtrace(get_string('import:importdone', 'local_cpdlog', importer::import_run((int) $run->id)));
    }
}
