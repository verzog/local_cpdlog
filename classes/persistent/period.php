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
 * CPD reporting period persistent.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\persistent;

use core\lang_string;

/**
 * A CPD reporting period, such as a calendar year or a triennium.
 *
 * The end date is exclusive: it is the start of the day after the last day, so the last day is
 * included when entries are compared with `activitydate < enddate`. The first and last days are
 * kept in the Moodle calendar (local_cpdlog\local\calendar).
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
     * Adds the new period's dates to the calendar.
     */
    protected function after_create() {
        \local_cpdlog\local\calendar::sync_period($this);
    }

    /**
     * Moves the period's calendar events to its new dates and name.
     *
     * @param bool $result Whether the update succeeded.
     */
    protected function after_update($result) {
        if ($result) {
            \local_cpdlog\local\calendar::sync_period($this);
        }
    }

    /**
     * Removes the deleted period's calendar events and the reminders sent about it.
     *
     * @param bool $result Whether the delete succeeded.
     */
    protected function after_delete($result) {
        global $DB;
        if ($result) {
            \local_cpdlog\local\calendar::remove_period((int) $this->get('id'));
            // Reminders about the period go with it; they are not CPD records.
            $DB->delete_records(\local_cpdlog\local\reminder::TABLE, ['periodid' => $this->get('id')]);
        }
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
