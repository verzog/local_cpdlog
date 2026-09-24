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
 * Base class for CPD entry events.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
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
