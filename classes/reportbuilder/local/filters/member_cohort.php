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
 * Report filter for members of the chosen cohorts.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\reportbuilder\local\filters;

use core_reportbuilder\local\helpers\database;

/**
 * Report filter for members of the chosen cohorts.
 *
 * The filter's field is a user id. Staff pick cohorts with the core cohort picker, and a row is kept
 * when its user belongs to any of them. Membership is tested with EXISTS rather than a join, so a
 * member of several chosen cohorts still appears once.
 */
class member_cohort extends \core_reportbuilder\local\filters\cohort
{
    /**
     * Returns the SQL keeping rows whose user is in any of the chosen cohorts.
     *
     * @param array $values The filter values.
     * @return array The SQL and its parameters; empty when no cohort is chosen.
     */
    public function get_sql_filter(array $values): array {
        global $DB;

        $cohortids = $values["{$this->name}_values"] ?? [];
        if (empty($cohortids)) {
            return ['', []];
        }

        $fieldsql = $this->filter->get_field_sql();
        [$insql, $params] = $DB->get_in_or_equal($cohortids, SQL_PARAMS_NAMED, database::generate_param_name('_'));
        $alias = database::generate_alias();
        $sql = "EXISTS (SELECT 1 FROM {cohort_members} {$alias}
                         WHERE {$alias}.userid = {$fieldsql} AND {$alias}.cohortid {$insql})";
        return [$sql, array_merge($this->filter->get_field_params(), $params)];
    }
}
