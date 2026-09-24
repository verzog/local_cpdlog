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
 * Form to add or edit a CPD reporting period.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\form;

use local_cpdlog\local\dates;

/**
 * Form to add or edit a CPD reporting period.
 *
 * Staff pick the last day of the period; it is stored as the exclusive end date, the start of the
 * following day. Dates are whole days in the site timezone.
 */
class period_form extends \core\form\persistent
{
    /** @var string Persistent class the form edits. */
    protected static $persistentclass = \local_cpdlog\persistent\period::class;

    /** @var array Fields that are not persistent properties. */
    protected static $foreignfields = ['lastday'];

    /**
     * Defines the form fields.
     */
    public function definition(): void {
        $mform = $this->_form;
        $dateoptions = ['timezone' => \core_date::get_server_timezone()];

        $mform->addElement('text', 'name', get_string('name'), ['size' => 50]);
        $mform->addRule('name', get_string('required'), 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement('date_selector', 'startdate', get_string('firstday', 'local_cpdlog'), $dateoptions);
        $mform->addElement('date_selector', 'lastday', get_string('lastday', 'local_cpdlog'), $dateoptions);
        $mform->addHelpButton('lastday', 'lastday', 'local_cpdlog');

        $this->add_action_buttons();
    }

    /**
     * Converts the last day picked into the exclusive end date.
     *
     * @param \stdClass $data The submitted data.
     * @return \stdClass
     */
    protected static function convert_fields(\stdClass $data) {
        $data = parent::convert_fields($data);
        if (isset($data->lastday)) {
            $data->enddate = dates::next_day_start((int) $data->lastday);
            unset($data->lastday);
        }
        return $data;
    }

    /**
     * Shows the stored exclusive end date as the last day, and leaves new dates to default to today.
     *
     * @return \stdClass
     */
    protected function get_default_data() {
        $data = parent::get_default_data();
        if (!empty($data->enddate)) {
            $data->lastday = dates::previous_day_start((int) $data->enddate);
        }
        if (empty($data->startdate)) {
            unset($data->startdate);
        }
        unset($data->enddate);
        return $data;
    }

    /**
     * Shows end date errors against the last day field, which is the one staff edit.
     *
     * @param \stdClass $data The submitted data.
     * @param array $files Submitted files.
     * @param array $errors Errors found so far.
     * @return array
     */
    protected function extra_validation($data, $files, array &$errors) {
        if (isset($errors['enddate'])) {
            $errors['lastday'] = $errors['enddate'];
            unset($errors['enddate']);
        }
        return [];
    }
}
