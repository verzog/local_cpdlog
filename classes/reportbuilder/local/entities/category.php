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
 * Report entity for CPD categories.
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
use local_cpdlog\persistent\category as category_persistent;

/**
 * Report entity for CPD categories.
 */
class category extends base
{
    /**
     * Returns the tables the entity uses.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return [category_persistent::TABLE];
    }

    /**
     * Returns the entity title.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('report:category', 'local_cpdlog');
    }

    /**
     * Adds the entity's columns, filters and conditions.
     *
     * @return base
     */
    public function initialise(): base {
        $alias = $this->get_table_alias(category_persistent::TABLE);

        $this->add_column((new column('name', new lang_string('name'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$alias}.name")
            ->set_is_sortable(true)
            ->add_callback(static function (?string $name): string {
                return $name === null ? '' : format_string($name, true, ['context' => \context_system::instance()]);
            }));

        $this->add_column((new column('shortname', new lang_string('shortname', 'local_cpdlog'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$alias}.shortname")
            ->set_is_sortable(true));

        $filter = (new filter(select::class, 'name', new lang_string('name'), $this->get_entity_name(), "{$alias}.id"))
            ->add_joins($this->get_joins())
            ->set_options_callback(static function (): array {
                $options = [];
                foreach (category_persistent::get_records([], 'sortorder') as $category) {
                    $options[$category->get('id')] = format_string($category->get('name'));
                }
                return $options;
            });
        $this->add_filter($filter)->add_condition($filter);

        return $this;
    }
}
