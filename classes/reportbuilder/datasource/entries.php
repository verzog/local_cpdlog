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
 * Report Builder source for CPD entries.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\reportbuilder\datasource;

use core_reportbuilder\datasource;
use core_reportbuilder\local\entities\user;
use local_cpdlog\persistent\category as category_persistent;
use local_cpdlog\persistent\entry as entry_persistent;
use local_cpdlog\persistent\period as period_persistent;
use local_cpdlog\reportbuilder\local\entities\{category, entry, member, period};

/**
 * Report Builder source for CPD entries: one row per entry, with its member, category and period.
 *
 * Members can be filtered by name, custom profile field (from the user entity) and cohort.
 */
class entries extends datasource
{
    /**
     * Returns the source name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('report:entries', 'local_cpdlog');
    }

    /**
     * Sets up the report's tables and entities.
     */
    protected function initialise(): void {
        $entry = new entry();
        $entryalias = $entry->get_table_alias(entry_persistent::TABLE);
        $this->set_main_table(entry_persistent::TABLE, $entryalias);
        $this->add_entity($entry);

        $user = new user();
        $useralias = $user->get_table_alias('user');
        $userjoin = "LEFT JOIN {user} {$useralias} ON {$useralias}.id = {$entryalias}.userid";
        $this->add_entity($user->add_join($userjoin));
        $this->add_entity((new member())->set_table_alias('user', $useralias)->add_join($userjoin));

        $category = new category();
        $categoryalias = $category->get_table_alias(category_persistent::TABLE);
        $this->add_entity($category->add_join(
            "LEFT JOIN {" . category_persistent::TABLE . "} {$categoryalias} ON {$categoryalias}.id = {$entryalias}.categoryid"
        ));

        $period = new period();
        $periodalias = $period->get_table_alias(period_persistent::TABLE);
        $this->add_entity($period->add_join(
            "LEFT JOIN {" . period_persistent::TABLE . "} {$periodalias} ON {$periodalias}.id = {$entryalias}.periodid"
        ));

        $this->add_all_from_entities();
    }

    /**
     * Returns the columns of a new report using the default setup.
     *
     * @return string[]
     */
    public function get_default_columns(): array {
        return [
            'user:fullnamewithlink',
            'entry:activitydate',
            'category:name',
            'entry:coursename',
            'entry:hours',
            'entry:status',
        ];
    }

    /**
     * Returns the filters of a new report using the default setup.
     *
     * @return string[]
     */
    public function get_default_filters(): array {
        return ['period:name', 'entry:status', 'category:name', 'member:cohort', 'user:fullname'];
    }

    /**
     * Returns the conditions of a new report using the default setup.
     *
     * @return string[]
     */
    public function get_default_conditions(): array {
        return [];
    }

    /**
     * Returns the sorting of a new report using the default setup.
     *
     * @return int[] Sort direction keyed by column.
     */
    public function get_default_column_sorting(): array {
        return ['entry:activitydate' => SORT_DESC];
    }
}
