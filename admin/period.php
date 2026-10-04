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
 * Adds or edits a CPD reporting period.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_cpdlog\form\period_form;
use local_cpdlog\persistent\period;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$id = optional_param('id', 0, PARAM_INT);
$url = new moodle_url('/local/cpdlog/admin/period.php', ['id' => $id]);

// Checks login and local/cpdlog:manageperiods at system context.
admin_externalpage_setup('local_cpdlog_periods', '', null, $url);

$returnurl = new moodle_url('/local/cpdlog/admin/periods.php');
$period = $id ? new period($id) : null;
if ($period && $period->is_closed()) {
    throw new moodle_exception('error:periodclosed', 'local_cpdlog', $returnurl);
}
$form = new period_form($url, ['persistent' => $period]);

if ($form->is_cancelled()) {
    redirect($returnurl);
}
if ($data = $form->get_data()) {
    $period = $period ?? new period();
    $period->from_record($data);
    $period->save();
    redirect($returnurl, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$heading = $period ? get_string('editperiod', 'local_cpdlog') : get_string('addperiod', 'local_cpdlog');
$PAGE->navbar->add($heading);
echo $OUTPUT->header();
echo $OUTPUT->heading($heading);
$form->display();
echo $OUTPUT->footer();
