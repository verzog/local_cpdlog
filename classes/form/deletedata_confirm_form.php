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
 * Form for staff to confirm deleting a member's CPD data by typing the member's username.
 *
 * An account already deleted in Moodle has a scrambled username, so its user ID is typed instead.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
