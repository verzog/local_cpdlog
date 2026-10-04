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
 * Base class for CPD entry events.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\event;

use local_cpdlog\persistent\entry;

/**
 * Base class for CPD entry events.
 *
 * Every change to an entry fires an event, so the standard log is the entry's audit trail.
 */
abstract class entry_base extends \core\event\base
{
    /**
     * Sets the table and teaching level shared by entry events.
     */
    protected function init() {
        $this->data['objecttable'] = entry::TABLE;
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Creates the event for an entry.
     *
     * @param entry $entry The entry; its record is snapshotted for observers.
     * @return static
     */
    public static function create_from_entry(entry $entry): static {
        $event = static::create([
            'objectid' => $entry->get('id'),
            'context' => \context_system::instance(),
            'relateduserid' => $entry->get('userid'),
        ]);
        $event->add_record_snapshot(entry::TABLE, $entry->to_record());
        return $event;
    }

    /**
     * Returns the member's logbook.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/cpdlog/index.php');
    }

    /**
     * Checks that the event names the member the entry belongs to.
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid must be set.');
        }
    }

    /**
     * Maps the entry id for backup and restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => entry::TABLE, 'restore' => \core\event\base::NOT_MAPPED];
    }
}
