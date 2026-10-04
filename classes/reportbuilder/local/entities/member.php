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
 * Report entity adding member filters to CPD reports.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\reportbuilder\local\entities;

use core\lang_string;
use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\report\filter;
use local_cpdlog\reportbuilder\local\filters\member_cohort;

/**
 * Report entity adding member filters to CPD reports.
 *
 * It shares the user table alias of the report's user entity, so its filters apply to the member
 * on each row. Name and custom profile field filters come from the core user entity.
 */
class member extends base
{
    /**
     * Returns the tables the entity uses.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return ['user'];
    }

    /**
     * Returns the entity title.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('member', 'local_cpdlog');
    }

    /**
     * Adds the entity's filters and conditions.
     *
     * @return base
     */
    public function initialise(): base {
        $alias = $this->get_table_alias('user');

        $filter = (new filter(
            member_cohort::class,
            'cohort',
            new lang_string('report:membercohort', 'local_cpdlog'),
            $this->get_entity_name(),
            "{$alias}.id"
        ))
            ->add_joins($this->get_joins());
        $this->add_filter($filter)->add_condition($filter);

        return $this;
    }
}
