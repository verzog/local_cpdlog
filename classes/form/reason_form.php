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
 * Form for staff to give a reason when rejecting or reversing a CPD entry.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for staff to give a reason when rejecting or reversing a CPD entry.
 *
 * Custom data: action, either 'reject' or 'reverse'. It picks the field label, help, error and button.
 */
class reason_form extends \moodleform
{
    /**
     * Defines the form fields.
     */
    public function definition(): void {
        $mform = $this->_form;
        $reject = $this->_customdata['action'] === 'reject';
        $label = $reject ? 'rejectionreason' : 'reversalreason';
        $mform->addElement('textarea', 'reason', get_string($label, 'local_cpdlog'), ['rows' => 5, 'cols' => 60]);
        $mform->setType('reason', PARAM_TEXT);
        $mform->addRule('reason', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('reason', $label, 'local_cpdlog');
        $this->add_action_buttons(true, $reject ? get_string('reject') : get_string('reverse', 'local_cpdlog'));
    }

    /**
     * Requires a reason that is not only spaces.
     *
     * @param array $data The submitted data.
     * @param array $files The submitted files.
     * @return string[] Errors keyed by field name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (trim($data['reason'] ?? '') === '') {
            $error = $this->_customdata['action'] === 'reject' ? 'error:reasonrequired' : 'error:reversalreasonrequired';
            $errors['reason'] = get_string($error, 'local_cpdlog');
        }
        return $errors;
    }
}
