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
 * Form for a member to log or edit a CPD entry.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
        $externalallowed = $entry && $entry->is_external();
        foreach (category::get_records([], 'sortorder') as $category) {
            if ($category->get('enabled') || ($entry && (int) $entry->get('categoryid') === (int) $category->get('id'))) {
                $categories[$category->get('id')] = format_string($category->get('name'));
                $externalallowed = $externalallowed || ($category->get('enabled') && $category->get('allowexternal'));
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
        // An activity outside Moodle can be logged when some category accepts external activities.
        if ($externalallowed) {
            $courses[entry::EXTERNAL_COURSE] = get_string('externalactivity', 'local_cpdlog');
        }
        $mform->addElement('select', 'courseid', get_string('course'), ['' => get_string('choosedots')] + $courses);
        $mform->addRule('courseid', get_string('required'), 'required', null, 'client');
        $mform->setType('courseid', PARAM_INT);
        $mform->addHelpButton('courseid', $externalallowed ? 'entrycourseexternal' : 'entrycourse', 'local_cpdlog');

        if ($externalallowed) {
            foreach (['activityname', 'provider'] as $field) {
                $mform->addElement('text', $field, get_string($field, 'local_cpdlog'), ['size' => 50]);
                $mform->setType($field, PARAM_TEXT);
                $mform->addRule($field, get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
                $mform->hideIf($field, 'courseid', 'neq', (string) entry::EXTERNAL_COURSE);
            }
            $mform->addHelpButton('activityname', 'activityname', 'local_cpdlog');
        }

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

        $mform->addElement(
            'filemanager',
            'evidence_filemanager',
            get_string('evidence', 'local_cpdlog'),
            null,
            entry_manager::evidence_options()
        );
        $mform->addHelpButton('evidence_filemanager', 'evidence', 'local_cpdlog');

        $buttons = [
            $mform->createElement('submit', 'savedraft', get_string('savedraft', 'local_cpdlog')),
            $mform->createElement('submit', 'saveandsubmit', get_string('saveandsubmit', 'local_cpdlog')),
            $mform->createElement('cancel'),
        ];
        $mform->addGroup($buttons, 'buttonar', '', ' ', false);
        $mform->closeHeaderBefore('buttonar');
    }

    /**
     * Returns the description editor options. Evidence files have their own file manager.
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
        $errors += entry_manager::validate((int) $this->_customdata['userid'], (object) $data, $this->_customdata['entry']);

        // Submitting straight away needs evidence for external activities and for categories that
        // require it; drafts do not. The draft area holds the files as they will be saved, including
        // ones already on the entry.
        if (!empty($data['saveandsubmit']) && empty($errors['categoryid'])) {
            $external = entry_manager::is_external_data((object) $data);
            $draftfiles = entry_manager::count_draft_files((int) ($data['evidence_filemanager'] ?? 0));
            if (entry_manager::needs_evidence((int) $data['categoryid'], $external) && !$draftfiles) {
                $errors['evidence_filemanager'] = get_string(
                    $external ? 'error:evidenceexternal' : 'error:evidencerequired',
                    'local_cpdlog'
                );
            }
        }
        return $errors;
    }
}
