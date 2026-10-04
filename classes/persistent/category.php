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
 * CPD category persistent.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\persistent;

use core\lang_string;

/**
 * A CPD category, such as Educational activities.
 *
 * Categories are disabled rather than deleted so existing entries are never orphaned.
 */
class category extends \core\persistent
{
    /** @var string Database table. */
    const TABLE = 'local_cpdlog_category';

    /**
     * Defines the properties of a category.
     *
     * @return array
     */
    protected static function define_properties(): array {
        return [
            'name' => [
                'type' => PARAM_TEXT,
            ],
            'shortname' => [
                'type' => PARAM_ALPHANUMEXT,
            ],
            'sortorder' => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
            'enabled' => [
                'type' => PARAM_BOOL,
                'default' => true,
            ],
            'evidencerequired' => [
                'type' => PARAM_BOOL,
                'default' => false,
            ],
        ];
    }

    /**
     * Validates the name.
     *
     * @param string $value The name.
     * @return true|lang_string
     */
    protected function validate_name($value) {
        if (trim($value) === '') {
            return new lang_string('required');
        }
        return true;
    }

    /**
     * Validates that the short name is present and unique.
     *
     * @param string $value The short name.
     * @return true|lang_string
     */
    protected function validate_shortname($value) {
        if ($value === '') {
            return new lang_string('required');
        }
        if (self::record_exists_select('shortname = :shortname AND id <> :id', ['shortname' => $value, 'id' => $this->get('id')])) {
            return new lang_string('error:shortnametaken', 'local_cpdlog');
        }
        return true;
    }

    /**
     * Moves a category one place up or down the list.
     *
     * Sort orders are renumbered from zero, so duplicates or gaps cannot block a move.
     *
     * @param int $id The category to move.
     * @param bool $up True to move it up, false to move it down.
     */
    public static function move(int $id, bool $up): void {
        global $DB, $USER;
        $current = $DB->get_records_menu(self::TABLE, null, 'sortorder, id', 'id, sortorder');
        $ids = array_map('intval', array_keys($current));
        $position = array_search($id, $ids, true);
        if ($position === false) {
            throw new \invalid_parameter_exception('Unknown category ' . $id);
        }
        $swap = $up ? $position - 1 : $position + 1;
        if (isset($ids[$swap])) {
            [$ids[$position], $ids[$swap]] = [$ids[$swap], $ids[$position]];
        }
        // Only rows whose position changes are touched, and they record who changed them and when.
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        foreach ($ids as $sortorder => $categoryid) {
            if ((int) $current[$categoryid] !== $sortorder) {
                $DB->update_record(self::TABLE, (object) [
                    'id' => $categoryid,
                    'sortorder' => $sortorder,
                    'timemodified' => $now,
                    'usermodified' => $USER->id,
                ]);
            }
        }
        $transaction->allow_commit();
    }

    /**
     * Returns the sort order for a new category, after all existing ones.
     *
     * @return int
     */
    public static function next_sortorder(): int {
        global $DB;
        return (int) $DB->get_field_sql('SELECT COALESCE(MAX(sortorder), -1) + 1 FROM {' . self::TABLE . '}');
    }
}
