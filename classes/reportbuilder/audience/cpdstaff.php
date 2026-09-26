<?php
// Copyright (c) Skin Cancer College Australasia.
// All rights reserved.
//
// This file is part of a proprietary plugin developed by Skin Cancer
// College Australasia for use with Moodle. It is NOT free software and is
// NOT released under the GNU General Public License.
//
// Unauthorised copying, distribution, modification, or use of this file,
// in whole or in part, via any medium, is strictly prohibited without the
// prior written permission of Skin Cancer College Australasia. The software
// is provided "as is", without warranty of any kind, express or implied.
/**
 * Report audience of CPD staff: users who can view every member's logbook.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
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
