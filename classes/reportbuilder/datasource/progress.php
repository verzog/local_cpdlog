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
 * Report Builder source for members' progress against CPD targets.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\reportbuilder\datasource;

use core_reportbuilder\datasource;
use core_reportbuilder\local\entities\user;
use core_reportbuilder\local\helpers\database;
use local_cpdlog\persistent\entry as entry_persistent;
use local_cpdlog\persistent\period as period_persistent;
use local_cpdlog\local\target_resolver;
use local_cpdlog\persistent\target;
use local_cpdlog\reportbuilder\local\entities\{member, period, target_progress};

/**
 * Report Builder source for members' progress: one row per member per target that applies to them.
 *
 * Members of a period are those who logged CPD in it, or who belong to a cohort with targets in it
 * (decision 16). Which targets apply follows local_cpdlog\local\target_resolver: all-members targets
 * apply to everyone; a cohort's targets are added when it is the member's only cohort with targets in
 * the period, or when staff chose it to resolve a conflict.
 */
class progress extends datasource
{
    /**
     * Returns the source name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('report:progresssource', 'local_cpdlog');
    }

    /**
     * Sets up the report's tables, entities and the rule for which targets apply.
     */
    protected function initialise(): void {
        [$members, $entries, $cohortmembers, $cohorttargets, $applies, $counted, $counttargets, $choice] =
            database::generate_aliases(8);

        $progress = new target_progress();
        $targetalias = $progress->get_table_alias(target::TABLE);
        $this->set_main_table(target::TABLE, $targetalias);

        // Everyone who logged CPD in the target's period, or is in a cohort with targets in it.
        $this->add_join("JOIN (SELECT {$entries}.userid, {$entries}.periodid
                                 FROM {" . entry_persistent::TABLE . "} {$entries}
                                UNION
                               SELECT {$cohortmembers}.userid, {$cohorttargets}.periodid
                                 FROM {cohort_members} {$cohortmembers}
                                 JOIN {" . target::TABLE . "} {$cohorttargets}
                                      ON {$cohorttargets}.cohortid = {$cohortmembers}.cohortid) {$members}
                            ON {$members}.periodid = {$targetalias}.periodid");

        $user = new user();
        $useralias = $user->get_table_alias('user');
        $this->add_join("JOIN {user} {$useralias} ON {$useralias}.id = {$members}.userid AND {$useralias}.deleted = 0");

        // A cohort target applies to its members when it is their only cohort with targets in the period,
        // or when staff chose it for a member with a conflict. The same rule as target_resolver.
        // Bracketed as a whole, because Report Builder joins it to filters with AND.
        $this->add_base_condition_sql("({$targetalias}.cohortid IS NULL OR (
            EXISTS (SELECT 1 FROM {cohort_members} {$applies}
                     WHERE {$applies}.cohortid = {$targetalias}.cohortid AND {$applies}.userid = {$members}.userid)
            AND ((SELECT COUNT(DISTINCT {$counted}.cohortid)
                    FROM {cohort_members} {$counted}
                    JOIN {" . target::TABLE . "} {$counttargets}
                         ON {$counttargets}.cohortid = {$counted}.cohortid AND {$counttargets}.periodid = {$targetalias}.periodid
                   WHERE {$counted}.userid = {$members}.userid) = 1
                 OR EXISTS (SELECT 1 FROM {" . target_resolver::CHOICE_TABLE . "} {$choice}
                             WHERE {$choice}.userid = {$members}.userid AND {$choice}.periodid = {$targetalias}.periodid
                               AND {$choice}.cohortid = {$targetalias}.cohortid))))");

        $this->add_entity($progress->set_member_field("{$members}.userid"));
        $this->add_entity($user);
        $this->add_entity((new member())->set_table_alias('user', $useralias));

        $period = new period();
        $periodalias = $period->get_table_alias(period_persistent::TABLE);
        $this->add_entity($period->add_join(
            "LEFT JOIN {" . period_persistent::TABLE . "} {$periodalias} ON {$periodalias}.id = {$targetalias}.periodid"
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
            'period:name',
            'user:fullnamewithlink',
            'target_progress:name',
            'target_progress:requiredhours',
            'target_progress:approvedhours',
            'target_progress:pendinghours',
            'target_progress:met',
        ];
    }

    /**
     * Returns the filters of a new report using the default setup.
     *
     * @return string[]
     */
    public function get_default_filters(): array {
        return ['period:name', 'member:cohort', 'target_progress:met', 'user:fullname'];
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
        return ['period:name' => SORT_DESC, 'user:fullnamewithlink' => SORT_ASC];
    }
}
