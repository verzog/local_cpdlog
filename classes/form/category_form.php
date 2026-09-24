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
 * Form to add or edit a CPD category.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
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
