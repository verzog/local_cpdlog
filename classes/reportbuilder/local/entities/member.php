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
 * Report entity adding member filters to CPD reports.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
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
