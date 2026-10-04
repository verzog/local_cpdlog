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
 * Ticking "Look for a deleted account" searches accounts already deleted in Moodle instead, by their
 * original username, original email address or user ID.
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
        $mform->addElement('advcheckbox', 'deletedaccount', get_string('deletedata:deletedaccount', 'local_cpdlog'));
        $mform->addHelpButton('deletedaccount', 'deletedata:deletedaccount', 'local_cpdlog');
        $this->add_action_buttons(false, get_string('deletedata:find', 'local_cpdlog'));
    }

    /**
     * Requires the identifier of exactly one active account, or of exactly one deleted account.
     *
     * @param array $data The submitted data.
     * @param array $files The submitted files.
     * @return string[] Errors keyed by field name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $deleted = !empty($data['deletedaccount']);
        if (!self::find_user(trim($data['member'] ?? ''), $deleted)) {
            $errors['member'] = get_string($deleted ? 'error:deletionnodeleted' : 'error:deletionnomember', 'local_cpdlog');
        }
        return $errors;
    }

    /**
     * Returns the one active account with this username or email address, or the one deleted account.
     *
     * Both the username and the email address are checked. If they lead to different accounts, or
     * several accounts share the email address, no account is returned, so an identifier that is one
     * person's username and another person's email address can never pick the wrong member.
     *
     * @param string $member A username or email address, or for a deleted account also a user ID.
     * @param bool $deleted Whether to look for an account already deleted in Moodle instead.
     * @return \stdClass|null
     */
    public static function find_user(string $member, bool $deleted = false): ?\stdClass {
        global $CFG, $DB;
        if ($member === '') {
            return null;
        }
        if ($deleted) {
            return self::find_deleted_user($member);
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

    /**
     * Returns the one deleted account with this user ID, original username or original email address.
     *
     * Moodle scrambles a deleted account: its username becomes its old email address (cleaned as a
     * username) followed by a dot and a timestamp, and its email becomes the MD5 hash of its old
     * username. The identifier is matched exactly against the user ID and both of these. As for active
     * accounts, an identifier that matches several deleted accounts finds none; the user ID then
     * usually picks the right one.
     *
     * @param string $member A user ID, original username or original email address.
     * @return \stdClass|null
     */
    private static function find_deleted_user(string $member): ?\stdClass {
        global $DB;
        // Moodle keeps at most 89 characters of the old email address, then a dot and a 10-digit timestamp.
        $prefix = \core_text::substr(clean_param($member, PARAM_USERNAME), 0, 89);
        $hash = md5(\core_text::strtolower($member));
        $id = ctype_digit($member) ? (int) $member : 0;
        $select = 'deleted = 1 AND (id = :id OR email = :hash OR ' . $DB->sql_like('username', ':prefix') . ')';
        $params = ['id' => $id, 'hash' => $hash, 'prefix' => $DB->sql_like_escape($prefix) . '.%'];
        $pattern = '/^' . preg_quote($prefix, '/') . '\.\d{10,}$/';
        $users = array_filter(
            $DB->get_records_select('user', $select, $params),
            fn($user) => (int) $user->id === $id || $user->email === $hash
                || ($prefix !== '' && preg_match($pattern, $user->username))
        );
        return count($users) === 1 ? reset($users) : null;
    }
}
