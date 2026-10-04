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
 * Report entity for CPD reporting periods.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\reportbuilder\local\entities;

use core\lang_string;
use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\report\{column, filter};
use local_cpdlog\local\dates;
use local_cpdlog\persistent\period as period_persistent;

/**
 * Report entity for CPD reporting periods.
 */
class period extends base
{
    /**
     * Returns the tables the entity uses.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return [period_persistent::TABLE];
    }

    /**
     * Returns the entity title.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('report:period', 'local_cpdlog');
    }

    /**
     * Adds the entity's columns, filters and conditions.
     *
     * @return base
     */
    public function initialise(): base {
        $alias = $this->get_table_alias(period_persistent::TABLE);

        $this->add_column((new column('name', new lang_string('name'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$alias}.name")
            ->set_is_sortable(true)
            ->add_callback(static function (?string $name): string {
                return $name === null ? '' : format_string($name, true, ['context' => \context_system::instance()]);
            }));

        $this->add_column((new column('firstday', new lang_string('firstday', 'local_cpdlog'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$alias}.startdate")
            ->set_is_sortable(true)
            ->add_callback([entry::class, 'format_day']));

        // The end date is stored as the start of the following day, so the last day is the day before it.
        $this->add_column((new column('lastday', new lang_string('lastday', 'local_cpdlog'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$alias}.enddate")
            ->set_is_sortable(true)
            ->add_callback(static function (?int $enddate): string {
                return $enddate ? entry::format_day(dates::previous_day_start($enddate)) : '';
            }));

        $this->add_column((new column('status', new lang_string('status'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$alias}.status")
            ->set_is_sortable(true)
            ->add_callback(static function (?string $status): string {
                return $status === null ? '' : get_string('status' . $status, 'local_cpdlog');
            }));

        $filter = (new filter(select::class, 'name', new lang_string('name'), $this->get_entity_name(), "{$alias}.id"))
            ->add_joins($this->get_joins())
            ->set_options_callback(static function (): array {
                $options = [];
                foreach (period_persistent::get_records([], 'startdate', 'DESC') as $period) {
                    $options[$period->get('id')] = format_string($period->get('name'));
                }
                return $options;
            });
        $this->add_filter($filter)->add_condition($filter);

        return $this;
    }
}
