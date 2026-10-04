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
 * Form to add or edit a CPD category.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\form;

/**
 * Form to add or edit a CPD category.
 */
class category_form extends \core\form\persistent
{
    /** @var string Persistent class the form edits. */
    protected static $persistentclass = \local_cpdlog\persistent\category::class;

    /**
     * Defines the form fields.
     */
    public function definition(): void {
        $mform = $this->_form;

        $mform->addElement('text', 'name', get_string('name'), ['size' => 50]);
        $mform->addRule('name', get_string('required'), 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement('text', 'shortname', get_string('shortname', 'local_cpdlog'), ['size' => 20]);
        $mform->addRule('shortname', get_string('required'), 'required', null, 'client');
        $mform->addRule('shortname', get_string('maximumchars', '', 100), 'maxlength', 100, 'client');
        $mform->addHelpButton('shortname', 'shortname', 'local_cpdlog');

        $mform->addElement('advcheckbox', 'evidencerequired', get_string('evidencerequired', 'local_cpdlog'));
        $mform->addHelpButton('evidencerequired', 'evidencerequired', 'local_cpdlog');

        $this->add_action_buttons();
    }
}
