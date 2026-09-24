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
 * CPD category persistent.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
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
     * Sort orders are renumbered from zero first, so duplicates or gaps cannot block a move.
     *
     * @param int $id The category to move.
     * @param bool $up True to move it up, false to move it down.
     */
    public static function move(int $id, bool $up): void {
        global $DB;
        $ids = array_map('intval', array_keys($DB->get_records(self::TABLE, null, 'sortorder, id', 'id')));
        $position = array_search($id, $ids, true);
        if ($position === false) {
            throw new \invalid_parameter_exception('Unknown category ' . $id);
        }
        $swap = $up ? $position - 1 : $position + 1;
        if (isset($ids[$swap])) {
            [$ids[$position], $ids[$swap]] = [$ids[$swap], $ids[$position]];
        }
        $transaction = $DB->start_delegated_transaction();
        foreach ($ids as $sortorder => $categoryid) {
            $DB->set_field(self::TABLE, 'sortorder', $sortorder, ['id' => $categoryid]);
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
