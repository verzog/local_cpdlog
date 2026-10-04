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
 * Report audience of CPD staff: users who can view every member's logbook.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\reportbuilder\audience;

use MoodleQuickForm;
use core_reportbuilder\local\audiences\base;

/**
 * Report audience of CPD staff: users with local/cpdlog:viewall at system context.
 *
 * The audience follows the capability, so assigning or removing the CPD staff role changes who sees
 * the reports without editing each report.
 */
class cpdstaff extends base
{
    /**
     * Explains the audience on its configuration form; it has no settings.
     *
     * @param MoodleQuickForm $mform The form.
     */
    public function get_config_form(MoodleQuickForm $mform): void {
        $mform->addElement(
            'static',
            'cpdstaff',
            get_string('audience:cpdstaff', 'local_cpdlog'),
            get_string('audience:cpdstaff_desc', 'local_cpdlog')
        );
    }

    /**
     * Returns the SQL selecting users with local/cpdlog:viewall.
     *
     * @param string $usertablealias The alias of the user table in the report query.
     * @return array Join SQL, where SQL and parameters.
     */
    public function get_sql(string $usertablealias): array {
        [$capsql, $params] = get_with_capability_sql(\context_system::instance(), 'local/cpdlog:viewall');
        return ['', "{$usertablealias}.id IN ({$capsql})", $params];
    }

    /**
     * Returns the audience name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('audience:cpdstaff', 'local_cpdlog');
    }

    /**
     * Returns the audience description shown on a report's audience list.
     *
     * @return string
     */
    public function get_description(): string {
        return get_string('audience:cpdstaff_desc', 'local_cpdlog');
    }

    /**
     * Whether the current user may add this audience to a report.
     *
     * @return bool
     */
    public function user_can_add(): bool {
        return has_capability('local/cpdlog:viewall', \context_system::instance());
    }

    /**
     * Whether the current user may edit this audience on a report.
     *
     * @return bool
     */
    public function user_can_edit(): bool {
        return $this->user_can_add();
    }
}
