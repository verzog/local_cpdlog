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
 * Form for staff to give a reason when rejecting or reversing a CPD entry.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
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
