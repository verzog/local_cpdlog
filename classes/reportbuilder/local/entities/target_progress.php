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
 * Report entity for a member's progress against a CPD target.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\reportbuilder\local\entities;

use core\lang_string;
use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\{boolean_select, number, text};
use core_reportbuilder\local\helpers\{database, format};
use core_reportbuilder\local\report\{column, filter};
use local_cpdlog\persistent\entry as entry_persistent;
use local_cpdlog\persistent\target;

/**
 * Report entity for a member's progress against a CPD target.
 *
 * Each row pairs a target with a member, whose user id the datasource supplies. The hours are worked
 * out in SQL with the same rules as local_cpdlog\local\progress: approved hours count, submitted hours
 * are pending, and a target counts its linked categories, or all categories when none are linked.
 */
class target_progress extends base
{
    /** @var string SQL for the user id of the member on each row. */
    private string $memberfield = '';

    /**
     * Sets the SQL for the user id of the member on each row. Call before adding the entity.
     *
     * @param string $sql The field, for example "m.userid".
     * @return self
     */
    public function set_member_field(string $sql): self {
        $this->memberfield = $sql;
        return $this;
    }

    /**
     * Returns the tables the entity uses.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return [target::TABLE];
    }

    /**
     * Returns the entity title.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('report:progress', 'local_cpdlog');
    }

    /**
     * Adds the entity's columns, filters and conditions.
     *
     * @return base
     */
    public function initialise(): base {
        $alias = $this->get_table_alias(target::TABLE);

        $this->add_column((new column('name', new lang_string('report:target', 'local_cpdlog'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$alias}.name")
            ->set_is_sortable(true)
            ->add_callback(static function (?string $name): string {
                return $name === null ? '' : format_string($name, true, ['context' => \context_system::instance()]);
            }));

        $title = new lang_string('requiredhours', 'local_cpdlog');
        $this->add_column((new column('requiredhours', $title, $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_FLOAT)
            ->add_field("{$alias}.requiredhours")
            ->set_is_sortable(true)
            ->add_callback([entry::class, 'format_hours']));

        $statuses = ['approved' => entry_persistent::STATUS_APPROVED, 'pending' => entry_persistent::STATUS_SUBMITTED];
        foreach ($statuses as $name => $status) {
            [$sql, $params] = $this->hours_sql($status);
            $title = new lang_string('report:' . $name . 'hours', 'local_cpdlog');
            $this->add_column((new column($name . 'hours', $title, $this->get_entity_name()))
                ->add_joins($this->get_joins())
                ->set_type(column::TYPE_FLOAT)
                ->add_field($sql, $name . 'hours', $params)
                ->set_is_sortable(true)
                ->add_callback([entry::class, 'format_hours']));
        }

        [$sql, $params] = $this->met_sql();
        $this->add_column((new column('met', new lang_string('targetmet', 'local_cpdlog'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_BOOLEAN)
            ->add_field($sql, 'met', $params)
            ->set_is_sortable(true)
            ->add_callback([format::class, 'boolean_as_text']));

        [$metsql, $metparams] = $this->met_sql();
        [$hourssql, $hoursparams] = $this->hours_sql(entry_persistent::STATUS_APPROVED);
        $percentsql = "CASE WHEN {$metsql} = 1 THEN 100 ELSE FLOOR({$hourssql} * 100 / {$alias}.requiredhours) END";
        $params = array_merge($metparams, $hoursparams);
        $this->add_column((new column('percent', new lang_string('report:percent', 'local_cpdlog'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field($percentsql, 'percent', $params)
            ->set_is_sortable(true)
            ->add_callback(static function ($percent): string {
                return $percent === null ? '' : get_string('percents', 'moodle', (int) $percent);
            }));

        $filters[] = (new filter(
            text::class,
            'name',
            new lang_string('report:target', 'local_cpdlog'),
            $this->get_entity_name(),
            "{$alias}.name"
        ))
            ->add_joins($this->get_joins());
        [$sql, $params] = $this->hours_sql(entry_persistent::STATUS_APPROVED);
        $filters[] = (new filter(
            number::class,
            'approvedhours',
            new lang_string('report:approvedhours', 'local_cpdlog'),
            $this->get_entity_name(),
            $sql,
            $params
        ))
            ->add_joins($this->get_joins());
        [$sql, $params] = $this->met_sql();
        $filters[] = (new filter(
            boolean_select::class,
            'met',
            new lang_string('targetmet', 'local_cpdlog'),
            $this->get_entity_name(),
            $sql,
            $params
        ))
            ->add_joins($this->get_joins());
        foreach ($filters as $filter) {
            $this->add_filter($filter)->add_condition($filter);
        }

        return $this;
    }

    /**
     * Returns SQL adding up the member's hours in one status that count towards the row's target.
     *
     * Every call uses fresh aliases and parameter names, so the SQL can appear more than once in a query.
     *
     * @param string $status The entry status to add up.
     * @return array The SQL and its parameters.
     */
    private function hours_sql(string $status): array {
        $alias = $this->get_table_alias(target::TABLE);
        [$entry, $linked, $counted] = database::generate_aliases(3);
        $param = database::generate_param_name();
        $sql = "(SELECT COALESCE(SUM({$entry}.hours), 0)
                   FROM {" . entry_persistent::TABLE . "} {$entry}
                  WHERE {$entry}.userid = {$this->memberfield}
                    AND {$entry}.periodid = {$alias}.periodid
                    AND {$entry}.status = :{$param}
                    AND (NOT EXISTS (SELECT 1
                                       FROM {" . target::CATEGORY_TABLE . "} {$linked}
                                      WHERE {$linked}.targetid = {$alias}.id)
                         OR {$entry}.categoryid IN (SELECT {$counted}.categoryid
                                                      FROM {" . target::CATEGORY_TABLE . "} {$counted}
                                                     WHERE {$counted}.targetid = {$alias}.id)))";
        return [$sql, [$param => $status]];
    }

    /**
     * Returns SQL that is 1 when the member's approved hours meet the row's target, else 0.
     *
     * @return array The SQL and its parameters.
     */
    private function met_sql(): array {
        $alias = $this->get_table_alias(target::TABLE);
        [$hourssql, $params] = $this->hours_sql(entry_persistent::STATUS_APPROVED);
        return ["CASE WHEN {$hourssql} >= {$alias}.requiredhours THEN 1 ELSE 0 END", $params];
    }
}
