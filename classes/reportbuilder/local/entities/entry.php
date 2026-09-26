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
 * Report entity for CPD entries.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\reportbuilder\local\entities;

use core\lang_string;
use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\{date, number, select, text};
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\{column, filter};
use local_cpdlog\persistent\entry as entry_persistent;

/**
 * Report entity for CPD entries.
 */
class entry extends base
{
    /**
     * Returns the tables the entity uses.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return [entry_persistent::TABLE];
    }

    /**
     * Returns the entity title.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('report:entry', 'local_cpdlog');
    }

    /**
     * Adds the entity's columns, filters and conditions.
     *
     * @return base
     */
    public function initialise(): base {
        foreach ($this->get_all_columns() as $column) {
            $this->add_column($column);
        }
        foreach ($this->get_all_filters() as $filter) {
            $this->add_filter($filter)->add_condition($filter);
        }
        return $this;
    }

    /**
     * Returns the entity's columns.
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        $alias = $this->get_table_alias(entry_persistent::TABLE);

        $columns[] = (new column('activitydate', new lang_string('activitydate', 'local_cpdlog'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$alias}.activitydate")
            ->set_is_sortable(true)
            ->add_callback([self::class, 'format_day']);

        $columns[] = (new column('hours', new lang_string('hours', 'local_cpdlog'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_FLOAT)
            ->add_field("{$alias}.hours")
            ->set_is_sortable(true)
            ->add_callback([self::class, 'format_hours']);

        $columns[] = (new column('status', new lang_string('status'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$alias}.status")
            ->set_is_sortable(true)
            ->add_callback(static function (?string $status): string {
                return $status === null ? '' : get_string('entrystatus:' . $status, 'local_cpdlog');
            });

        $columns[] = (new column('source', new lang_string('report:source', 'local_cpdlog'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$alias}.source")
            ->set_is_sortable(true)
            ->add_callback(static function (?string $source): string {
                return $source === null ? '' : get_string('source:' . $source, 'local_cpdlog');
            });

        $columns[] = (new column('coursename', new lang_string('course'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$alias}.coursename")
            ->set_is_sortable(true)
            ->add_callback(static function (?string $name): string {
                return $name === null ? '' : format_string($name, true, ['context' => \context_system::instance()]);
            });

        $columns[] = (new column('description', new lang_string('description'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_LONGTEXT)
            ->add_field("{$alias}.description")
            ->add_field("{$alias}.descriptionformat")
            ->add_callback(static function (?string $description, \stdClass $row): string {
                if ($description === null) {
                    return '';
                }
                return format_text($description, $row->descriptionformat, ['context' => \context_system::instance()]);
            });

        foreach (['timesubmitted', 'timereviewed', 'timereversed'] as $field) {
            $columns[] = (new column($field, new lang_string('report:' . $field, 'local_cpdlog'), $this->get_entity_name()))
                ->add_joins($this->get_joins())
                ->set_type(column::TYPE_TIMESTAMP)
                ->add_field("{$alias}.{$field}")
                ->set_is_sortable(true)
                ->add_callback([format::class, 'userdate']);
        }

        foreach (['rejectionreason', 'reversalreason'] as $field) {
            $columns[] = (new column($field, new lang_string($field, 'local_cpdlog'), $this->get_entity_name()))
                ->add_joins($this->get_joins())
                ->set_type(column::TYPE_LONGTEXT)
                ->add_field("{$alias}.{$field}")
                ->add_callback(static function (?string $reason): string {
                    return $reason === null ? '' : nl2br(s($reason));
                });
        }

        return $columns;
    }

    /**
     * Returns the entity's filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $alias = $this->get_table_alias(entry_persistent::TABLE);

        $filters[] = (new filter(
            date::class,
            'activitydate',
            new lang_string('activitydate', 'local_cpdlog'),
            $this->get_entity_name(),
            "{$alias}.activitydate"
        ))
            ->add_joins($this->get_joins());

        $filters[] = (new filter(
            number::class,
            'hours',
            new lang_string('hours', 'local_cpdlog'),
            $this->get_entity_name(),
            "{$alias}.hours"
        ))
            ->add_joins($this->get_joins());

        $filters[] = (new filter(select::class, 'status', new lang_string('status'), $this->get_entity_name(), "{$alias}.status"))
            ->add_joins($this->get_joins())
            ->set_options_callback(static function (): array {
                $options = [];
                foreach (entry_persistent::STATUSES as $status) {
                    $options[$status] = get_string('entrystatus:' . $status, 'local_cpdlog');
                }
                return $options;
            });

        $filters[] = (new filter(
            select::class,
            'source',
            new lang_string('report:source', 'local_cpdlog'),
            $this->get_entity_name(),
            "{$alias}.source"
        ))
            ->add_joins($this->get_joins())
            ->set_options_callback(static function (): array {
                return [
                    entry_persistent::SOURCE_MOODLE => get_string('source:moodle', 'local_cpdlog'),
                    entry_persistent::SOURCE_IMIS => get_string('source:imis', 'local_cpdlog'),
                ];
            });

        $filters[] = (new filter(
            text::class,
            'coursename',
            new lang_string('course'),
            $this->get_entity_name(),
            "{$alias}.coursename"
        ))
            ->add_joins($this->get_joins());

        $filters[] = (new filter(
            date::class,
            'timesubmitted',
            new lang_string('report:timesubmitted', 'local_cpdlog'),
            $this->get_entity_name(),
            "{$alias}.timesubmitted"
        ))
            ->add_joins($this->get_joins());

        return $filters;
    }

    /**
     * Formats an activity date as DD/MM/YYYY. Activity dates are whole days in the site timezone.
     *
     * @param int|null $value The timestamp.
     * @return string
     */
    public static function format_day(?int $value): string {
        if (!$value) {
            return '';
        }
        return userdate($value, get_string('strftimedatefull', 'local_cpdlog'), \core_date::get_server_timezone(), false);
    }

    /**
     * Formats hours with two decimal places.
     *
     * @param float|string|null $value The hours.
     * @return string
     */
    public static function format_hours($value): string {
        return $value === null ? '' : format_float((float) $value, 2);
    }
}
