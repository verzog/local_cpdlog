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
 * Form for staff to confirm deleting a member's CPD data by typing the member's username.
 *
 * An account already deleted in Moodle has a scrambled username, so its user ID is typed instead.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for staff to confirm deleting a member's CPD data by typing the member's username.
 *
 * Custom data: user, the member's record.
 */
class deletedata_confirm_form extends \moodleform
{
    /**
     * Defines the form fields.
     */
    public function definition(): void {
        $mform = $this->_form;
        $user = $this->_customdata['user'];
        $mform->addElement('hidden', 'userid', $user->id);
        $mform->setType('userid', PARAM_INT);
        $label = $user->deleted
            ? get_string('deletedata:confirmid', 'local_cpdlog', $user->id)
            : get_string('deletedata:confirm', 'local_cpdlog', s($user->username));
        $mform->addElement('text', 'confirmusername', $label, ['size' => 40, 'autocomplete' => 'off']);
        $mform->setType('confirmusername', PARAM_RAW_TRIMMED);
        $mform->addRule('confirmusername', get_string('required'), 'required', null, 'client');
        $this->add_action_buttons(true, get_string('deletedata:submit', 'local_cpdlog'));
    }

    /**
     * Requires the member's username, or the user ID of a deleted account, typed exactly.
     *
     * @param array $data The submitted data.
     * @param array $files The submitted files.
     * @return string[] Errors keyed by field name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $user = $this->_customdata['user'];
        $expected = $user->deleted ? (string) $user->id : $user->username;
        if (trim($data['confirmusername'] ?? '') !== $expected) {
            $error = $user->deleted ? 'error:deletionconfirmid' : 'error:deletionconfirm';
            $errors['confirmusername'] = get_string($error, 'local_cpdlog');
        }
        return $errors;
    }
}
