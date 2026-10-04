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
 * Form for staff to find the member whose CPD data they want to delete.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for staff to find the member whose CPD data they want to delete.
 *
 * The member is found by exact username or email address, so a near miss never selects someone else.
 */
class deletedata_find_form extends \moodleform
{
    /**
     * Defines the form fields.
     */
    public function definition(): void {
        $mform = $this->_form;
        $mform->addElement('text', 'member', get_string('deletedata:member', 'local_cpdlog'), ['size' => 40]);
        $mform->setType('member', PARAM_RAW_TRIMMED);
        $mform->addRule('member', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('member', 'deletedata:member', 'local_cpdlog');
        $this->add_action_buttons(false, get_string('deletedata:find', 'local_cpdlog'));
    }

    /**
     * Requires the username or email address of exactly one active account.
     *
     * @param array $data The submitted data.
     * @param array $files The submitted files.
     * @return string[] Errors keyed by field name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (!self::find_user(trim($data['member'] ?? ''))) {
            $errors['member'] = get_string('error:deletionnomember', 'local_cpdlog');
        }
        return $errors;
    }

    /**
     * Returns the one active account with this username or email address.
     *
     * Both the username and the email address are checked. If they lead to different accounts, or
     * several accounts share the email address, no account is returned, so an identifier that is one
     * person's username and another person's email address can never pick the wrong member.
     *
     * @param string $member A username or email address.
     * @return \stdClass|null
     */
    public static function find_user(string $member): ?\stdClass {
        global $CFG, $DB;
        if ($member === '') {
            return null;
        }
        $select = 'deleted = 0 AND ((username = :username AND mnethostid = :mnethostid) OR LOWER(email) = LOWER(:email))';
        $params = [
            'username' => \core_text::strtolower($member),
            'mnethostid' => $CFG->mnet_localhost_id,
            'email' => $member,
        ];
        $users = $DB->get_records_select('user', $select, $params, '', '*', 0, 2);
        if (count($users) !== 1) {
            return null;
        }
        $user = reset($users);
        return isguestuser($user) ? null : $user;
    }
}
