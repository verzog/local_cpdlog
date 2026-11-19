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
 * Form for staff to audit CPD history from another system.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for staff to audit CPD history: the source (iMIS or a CSV file), and everyone or one member.
 *
 * Two buttons: Run audit, and Show source fields, which lists the columns to help with the mapping.
 */
class import_audit_form extends \moodleform
{
    /**
     * Defines the form fields.
     */
    public function definition(): void {
        $mform = $this->_form;
        $mform->addElement('select', 'source', get_string('import:source', 'local_cpdlog'), [
            'imis' => get_string('import:source:imis', 'local_cpdlog'),
            'csv' => get_string('import:source:csv', 'local_cpdlog'),
        ]);
        $mform->setType('source', PARAM_ALPHA);

        $mform->addElement('filepicker', 'csvfile', get_string('import:csvfile', 'local_cpdlog'), null, [
            'accepted_types' => ['.csv'],
            'maxbytes' => 0,
        ]);
        $mform->addHelpButton('csvfile', 'import:csvfile', 'local_cpdlog');
        $mform->hideIf('csvfile', 'source', 'neq', 'csv');

        $mform->addElement('text', 'memberid', get_string('import:memberid', 'local_cpdlog'), ['size' => 20]);
        $mform->setType('memberid', PARAM_RAW_TRIMMED);
        $mform->addRule('memberid', get_string('maximumchars', '', 100), 'maxlength', 100, 'client');
        $mform->addHelpButton('memberid', 'import:memberid', 'local_cpdlog');

        $buttons = [
            $mform->createElement('submit', 'audit', get_string('import:runaudit', 'local_cpdlog')),
            $mform->createElement('submit', 'fields', get_string('import:showfields', 'local_cpdlog')),
        ];
        $mform->addGroup($buttons, 'buttonar', '', ' ', false);
        $mform->closeHeaderBefore('buttonar');
    }

    /**
     * Checks a CSV file is given for the CSV source, and iMIS is set up for the iMIS source.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by field name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (($data['source'] ?? '') === 'csv' && !$this->get_draft_files('csvfile')) {
            $errors['csvfile'] = get_string('required');
        }
        if (($data['source'] ?? '') === 'imis' && !(new \local_cpdlog\local\import\imis_iqa_source())->is_configured()) {
            $errors['source'] = get_string('import:notconfigured', 'local_cpdlog');
        }
        return $errors;
    }
}
