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
 * CPD target persistent.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
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
     * Validates that the required hours are positive and fit the column.
     *
     * @param float $value The required hours.
     * @return true|lang_string
     */
    protected function validate_requiredhours($value) {
        if ((float) $value <= 0 || (float) $value > self::MAX_HOURS) {
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
