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
 * CPD reporting period persistent.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\persistent;

use core\lang_string;

/**
 * A CPD reporting period, such as a calendar year or a triennium.
 *
 * The end date is exclusive: it is the start of the day after the last day, so the last day is
 * included when entries are compared with `activitydate < enddate`.
 */
class period extends \core\persistent
{
    /** @var string Database table. */
    const TABLE = 'local_cpdlog_period';

    /** @var string Status of a period that accepts entries. */
    const STATUS_OPEN = 'open';

    /** @var string Status of a period whose entries are locked. */
    const STATUS_CLOSED = 'closed';

    /**
     * Defines the properties of a period.
     *
     * @return array
     */
    protected static function define_properties(): array {
        return [
            'name' => [
                'type' => PARAM_TEXT,
            ],
            'startdate' => [
                'type' => PARAM_INT,
            ],
            'enddate' => [
                'type' => PARAM_INT,
            ],
            'status' => [
                'type' => PARAM_ALPHA,
                'choices' => [self::STATUS_OPEN, self::STATUS_CLOSED],
                'default' => self::STATUS_OPEN,
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
     * Validates that the period ends after it starts and overlaps no other period.
     *
     * @param int $value The exclusive end date.
     * @return true|lang_string
     */
    protected function validate_enddate($value) {
        $startdate = (int) $this->raw_get('startdate');
        if ((int) $value <= $startdate) {
            return new lang_string('error:periodenddate', 'local_cpdlog');
        }
        // Two periods overlap when each starts before the other ends. Adjacent periods do not.
        $select = 'startdate < :enddate AND enddate > :startdate AND id <> :id';
        $params = ['enddate' => (int) $value, 'startdate' => $startdate, 'id' => (int) $this->get('id')];
        if (self::record_exists_select($select, $params)) {
            return new lang_string('error:periodoverlap', 'local_cpdlog');
        }
        return true;
    }

    /**
     * Whether the period is closed, locking its entries and targets.
     *
     * @return bool
     */
    public function is_closed(): bool {
        return $this->get('status') === self::STATUS_CLOSED;
    }

    /**
     * Returns the last day of the period, as the start of that day.
     *
     * @return int
     */
    public function get_lastday(): int {
        return \local_cpdlog\local\dates::previous_day_start((int) $this->get('enddate'));
    }

    /**
     * Whether the period can be deleted: only while nothing refers to it.
     *
     * @return bool
     */
    public function can_delete(): bool {
        global $DB;
        $params = ['periodid' => $this->get('id')];
        return !$DB->record_exists('local_cpdlog_entry', $params)
            && !$DB->record_exists('local_cpdlog_target', $params)
            && !$DB->record_exists('local_cpdlog_cohortchoice', $params);
    }
}
