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
 * Event fired when staff delete a member's CPD data.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\event;

use local_cpdlog\local\data_deleter;

/**
 * Event fired when staff delete a member's CPD data.
 *
 * The user is the staff member who asked for the deletion; the related user is the member. The
 * numbers of entries and files deleted are kept in other.
 */
class member_data_deleted extends \core\event\base
{
    /**
     * Sets the event's table, action type and level.
     */
    protected function init() {
        $this->data['objecttable'] = data_deleter::TABLE;
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Creates the event for a completed deletion.
     *
     * @param \stdClass $deletion The register record.
     * @return static
     */
    public static function create_from_deletion(\stdClass $deletion): static {
        return static::create([
            'objectid' => $deletion->id,
            'context' => \context_system::instance(),
            'userid' => $deletion->requestedby,
            'relateduserid' => $deletion->userid,
            'other' => [
                'entriesdeleted' => (int) $deletion->entriesdeleted,
                'filesdeleted' => (int) $deletion->filesdeleted,
            ],
        ]);
    }

    /**
     * Returns the event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:memberdatadeleted', 'local_cpdlog');
    }

    /**
     * Returns a description of what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' deleted the CPD data of the user with id '$this->relateduserid': "
            . "{$this->other['entriesdeleted']} entries and {$this->other['filesdeleted']} evidence files.";
    }

    /**
     * Returns the deletion page.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/cpdlog/admin/deletedata.php');
    }

    /**
     * Checks that the event names the member and the numbers deleted.
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid must be set.');
        }
        if (!isset($this->other['entriesdeleted'], $this->other['filesdeleted'])) {
            throw new \coding_exception('The numbers of entries and files deleted must be set.');
        }
    }

    /**
     * Maps the register id for backup and restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => data_deleter::TABLE, 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * Maps the other data for backup and restore; it holds only counts.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
