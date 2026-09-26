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
 * Report filter for members of the chosen cohorts.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
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
