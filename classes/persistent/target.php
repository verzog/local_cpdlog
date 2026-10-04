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
 * CPD target persistent.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\persistent;

use core\lang_string;

/**
 * Required CPD hours in a period, optionally for one cohort.
 *
 * The categories that count towards a target are linked in local_cpdlog_target_cat. One linked
 * category is a per-category minimum, several make a combined minimum, and none an overall total.
 */
class target extends \core\persistent
{
    /** @var string Database table. */
    const TABLE = 'local_cpdlog_target';

    /** @var string Table linking targets to categories. */
    const CATEGORY_TABLE = 'local_cpdlog_target_cat';

    /** @var float Largest value the requiredhours column holds. */
    const MAX_HOURS = 999999.99;

    /**
     * Defines the properties of a target.
     *
     * @return array
     */
    protected static function define_properties(): array {
        return [
            'periodid' => [
                'type' => PARAM_INT,
            ],
            'cohortid' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'name' => [
                'type' => PARAM_TEXT,
            ],
            'requiredhours' => [
                'type' => PARAM_FLOAT,
            ],
            'sortorder' => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
        ];
    }

    /**
     * Validates that the period exists.
     *
     * @param int $value The period id.
     * @return true|lang_string
     */
    protected function validate_periodid($value) {
        if (!period::record_exists($value)) {
            return new lang_string('invalidrecord', 'error', period::TABLE);
        }
        return true;
    }

    /**
     * Validates that the cohort exists, when one is set.
     *
     * @param int|null $value The cohort id, or null for all members.
     * @return true|lang_string
     */
    protected function validate_cohortid($value) {
        global $DB;
        if ($value !== null && !$DB->record_exists('cohort', ['id' => $value])) {
            return new lang_string('invalidrecord', 'error', 'cohort');
        }
        return true;
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
     * Validates that the required hours are positive and fit the two-decimal column.
     *
     * Values with more decimal places are rejected rather than rounded, so 0.001 cannot be stored as 0.00.
     *
     * @param float $value The required hours.
     * @return true|lang_string
     */
    protected function validate_requiredhours($value) {
        $hours = (float) $value;
        if ($hours < 0.01 || $hours > self::MAX_HOURS || abs(round($hours, 2) - $hours) > 1e-9) {
            return new lang_string('error:targethours', 'local_cpdlog');
        }
        return true;
    }

    /**
     * Returns the ids of the categories that count towards this target.
     *
     * @return int[] Category ids; empty means every category counts.
     */
    public function get_categoryids(): array {
        global $DB;
        $params = ['targetid' => $this->get('id')];
        $ids = $DB->get_fieldset_select(self::CATEGORY_TABLE, 'categoryid', 'targetid = :targetid', $params);
        return array_map('intval', $ids);
    }

    /**
     * Replaces the categories that count towards this saved target.
     *
     * Callers saving the target and its categories together should wrap both in one transaction.
     *
     * @param int[] $categoryids Category ids; empty means every category counts.
     * @throws \invalid_parameter_exception If a category does not exist.
     */
    public function set_categoryids(array $categoryids): void {
        global $DB;
        if (!$this->get('id')) {
            throw new \coding_exception('The target must be saved before its categories are set.');
        }
        $categoryids = array_unique(array_map('intval', $categoryids));
        foreach ($categoryids as $categoryid) {
            if (!category::record_exists($categoryid)) {
                throw new \invalid_parameter_exception('Unknown category ' . $categoryid);
            }
        }
        $DB->delete_records(self::CATEGORY_TABLE, ['targetid' => $this->get('id')]);
        foreach ($categoryids as $categoryid) {
            $DB->insert_record(self::CATEGORY_TABLE, ['targetid' => $this->get('id'), 'categoryid' => $categoryid]);
        }
    }

    /**
     * Removes the target's category links once the target is deleted.
     *
     * @param bool $result Whether the target was deleted.
     */
    protected function after_delete($result): void {
        global $DB;
        if ($result) {
            $DB->delete_records(self::CATEGORY_TABLE, ['targetid' => $this->get('id')]);
        }
    }
}
