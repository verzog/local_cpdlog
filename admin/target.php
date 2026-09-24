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
 * Adds or edits a CPD target within a period.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

use local_cpdlog\form\target_form;
use local_cpdlog\persistent\period;
use local_cpdlog\persistent\target;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$periodid = required_param('periodid', PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);
$url = new moodle_url('/local/cpdlog/admin/target.php', ['periodid' => $periodid, 'id' => $id]);

// Checks login and local/cpdlog:manageperiods at system context.
admin_externalpage_setup('local_cpdlog_periods', '', null, $url);

$returnurl = new moodle_url('/local/cpdlog/admin/targets.php', ['periodid' => $periodid]);
$period = new period($periodid);
if ($period->is_closed()) {
    throw new moodle_exception('error:periodclosed', 'local_cpdlog', $returnurl);
}
$target = $id ? new target($id) : new target(0, (object) ['periodid' => $periodid]);
if ((int) $target->get('periodid') !== $periodid) {
    throw new moodle_exception('invalidrecord', 'error', $returnurl, target::TABLE);
}
$form = new target_form($url, ['persistent' => $target]);

if ($form->is_cancelled()) {
    redirect($returnurl);
}
if ($data = $form->get_data()) {
    // The tick boxes submit every category id with 1 when ticked and 0 when not.
    $categoryids = array_keys(array_filter((array) ($data->categoryids ?? [])));
    unset($data->categoryids);
    // The period comes from the checked URL parameter, never from the submitted form.
    $data->periodid = $periodid;

    $transaction = $DB->start_delegated_transaction();
    $target->from_record($data);
    $target->save();
    $target->set_categoryids($categoryids);
    $transaction->allow_commit();
    redirect($returnurl, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$heading = $id ? get_string('edittarget', 'local_cpdlog') : get_string('addtarget', 'local_cpdlog');
$PAGE->navbar->add(get_string('targetsfor', 'local_cpdlog', format_string($period->get('name'))), $returnurl);
$PAGE->navbar->add($heading);
echo $OUTPUT->header();
echo $OUTPUT->heading($heading);
$form->display();
echo $OUTPUT->footer();
