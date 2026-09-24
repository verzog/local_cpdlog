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
 * Adds or edits a CPD reporting period.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
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
