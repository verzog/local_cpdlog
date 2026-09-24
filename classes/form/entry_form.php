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
 * Form for a member to log or edit a CPD entry.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\form;

use local_cpdlog\local\entry_manager;
use local_cpdlog\persistent\category;
use local_cpdlog\persistent\entry;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for a member to log or edit a CPD entry.
 *
 * Custom data: userid (the member) and entry (the entry being edited, or null).
 */
class entry_form extends \moodleform
{
    /**
     * Defines the form fields.
     */
    public function definition(): void {
        $mform = $this->_form;
        $userid = (int) $this->_customdata['userid'];
        /** @var entry|null $entry */
        $entry = $this->_customdata['entry'];

        // Disabled categories stay listed only for the entry that already uses one.
        $categories = [];
        foreach (category::get_records([], 'sortorder') as $category) {
            if ($category->get('enabled') || ($entry && (int) $entry->get('categoryid') === (int) $category->get('id'))) {
                $categories[$category->get('id')] = format_string($category->get('name'));
            }
        }
        $mform->addElement('select', 'categoryid', get_string('category'), ['' => get_string('choosedots')] + $categories);
        $mform->addRule('categoryid', get_string('required'), 'required', null, 'client');
        $mform->setType('categoryid', PARAM_INT);

        // A course the member has since lost access to stays listed for the entry that uses it.
        $courses = entry_manager::get_course_options($userid);
        if ($entry && $entry->get('courseid') && !isset($courses[$entry->get('courseid')])) {
            $courses[$entry->get('courseid')] = format_string((string) $entry->get('coursename'));
        }
        $mform->addElement('select', 'courseid', get_string('course'), ['' => get_string('choosedots')] + $courses);
        $mform->addRule('courseid', get_string('required'), 'required', null, 'client');
        $mform->setType('courseid', PARAM_INT);
        $mform->addHelpButton('courseid', 'entrycourse', 'local_cpdlog');

        $mform->addElement('date_selector', 'activitydate', get_string('activitydate', 'local_cpdlog'), [
            'timezone' => \core_date::get_server_timezone(),
        ]);
        $mform->addHelpButton('activitydate', 'activitydate', 'local_cpdlog');

        $mform->addElement('text', 'hours', get_string('hours', 'local_cpdlog'), ['size' => 6]);
        $mform->setType('hours', PARAM_FLOAT);
        $mform->addRule('hours', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('hours', 'hours', 'local_cpdlog');

        $mform->addElement('editor', 'description_editor', get_string('description'), null, self::editor_options());
        $mform->setType('description_editor', PARAM_RAW);

        $buttons = [
            $mform->createElement('submit', 'savedraft', get_string('savedraft', 'local_cpdlog')),
            $mform->createElement('submit', 'saveandsubmit', get_string('saveandsubmit', 'local_cpdlog')),
            $mform->createElement('cancel'),
        ];
        $mform->addGroup($buttons, 'buttonar', '', ' ', false);
        $mform->closeHeaderBefore('buttonar');
    }

    /**
     * Returns the description editor options. Evidence files are uploaded separately.
     *
     * @return array
     */
    public static function editor_options(): array {
        return ['maxfiles' => 0, 'context' => \context_system::instance()];
    }

    /**
     * Checks the details against the logging rules.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by field name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        return $errors + entry_manager::validate(
            (int) $this->_customdata['userid'],
            (object) $data,
            $this->_customdata['entry']
        );
    }
}
