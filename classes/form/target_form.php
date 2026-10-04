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
 * Form to add or edit a CPD target.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\form;

use local_cpdlog\persistent\category;

/**
 * Form to add or edit a CPD target within a period.
 */
class target_form extends \core\form\persistent
{
    /** @var string Persistent class the form edits. */
    protected static $persistentclass = \local_cpdlog\persistent\target::class;

    /** @var array Fields that are not persistent properties. */
    protected static $foreignfields = ['categoryids'];

    /**
     * Defines the form fields.
     */
    public function definition(): void {
        global $DB;
        $mform = $this->_form;

        $mform->addElement('text', 'name', get_string('name'), ['size' => 50]);
        $mform->addRule('name', get_string('required'), 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $cohorts = [0 => get_string('allmembers', 'local_cpdlog')];
        foreach ($DB->get_records('cohort', null, 'name', 'id, name, contextid') as $cohort) {
            $context = \context::instance_by_id($cohort->contextid);
            $cohorts[$cohort->id] = format_string($cohort->name, true, ['context' => $context]);
        }
        $mform->addElement('select', 'cohortid', get_string('cohort', 'cohort'), $cohorts);
        $mform->setType('cohortid', PARAM_INT);
        $mform->addHelpButton('cohortid', 'targetcohort', 'local_cpdlog');

        // Disabled categories stay listed only while a target already counts them.
        $current = $this->get_persistent()->get('id') ? $this->get_persistent()->get_categoryids() : [];
        $categories = [];
        foreach (category::get_records([], 'sortorder') as $category) {
            if ($category->get('enabled') || in_array((int) $category->get('id'), $current, true)) {
                $categories[$category->get('id')] = format_string($category->get('name'));
            }
        }
        // Tick boxes rather than an autocomplete, so the form also works without JavaScript.
        $checkboxes = [];
        foreach ($categories as $categoryid => $name) {
            $checkboxes[] = $mform->createElement('advcheckbox', $categoryid, '', $name);
        }
        $label = get_string('targetcategories', 'local_cpdlog');
        $mform->addGroup($checkboxes, 'categoryids', $label, \html_writer::empty_tag('br'), true);
        $mform->addHelpButton('categoryids', 'targetcategories', 'local_cpdlog');

        $mform->addElement('text', 'requiredhours', get_string('requiredhours', 'local_cpdlog'), ['size' => 8]);
        $mform->addRule('requiredhours', get_string('required'), 'required', null, 'client');

        $this->add_action_buttons();
    }

    /**
     * Maps the "All members" choice to a null cohort.
     *
     * @param \stdClass $data The submitted data.
     * @return \stdClass
     */
    protected static function convert_fields(\stdClass $data) {
        $data = parent::convert_fields($data);
        if (property_exists($data, 'cohortid') && empty($data->cohortid)) {
            $data->cohortid = null;
        }
        return $data;
    }

    /**
     * Loads the linked categories and shows a null cohort as "All members".
     *
     * @return \stdClass
     */
    protected function get_default_data() {
        $data = parent::get_default_data();
        $data->cohortid = $data->cohortid ?? 0;
        $categoryids = $this->get_persistent()->get('id') ? $this->get_persistent()->get_categoryids() : [];
        $data->categoryids = array_fill_keys($categoryids, 1);
        return $data;
    }
}
