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
 * Event fired when a CPD entry is created.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\event;

/**
 * Event fired when a CPD entry is created.
 */
class entry_created extends entry_base
{
    /**
     * Sets the event's action type.
     */
    protected function init() {
        parent::init();
        $this->data['crud'] = 'c';
    }

    /**
     * Returns the event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:entrycreated', 'local_cpdlog');
    }

    /**
     * Returns a description of what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' created the CPD entry with id '$this->objectid' "
            . "belonging to the user with id '$this->relateduserid'.";
    }
}
