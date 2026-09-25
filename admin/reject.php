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
 * Rejects a submitted CPD entry, with a reason for the member.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

use local_cpdlog\form\reject_form;
use local_cpdlog\local\display;
use local_cpdlog\local\review_manager;
use local_cpdlog\persistent\category;
use local_cpdlog\persistent\entry;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$id = required_param('id', PARAM_INT);
$url = new moodle_url('/local/cpdlog/admin/reject.php', ['id' => $id]);

// Checks login and local/cpdlog:approve at system context.
admin_externalpage_setup('local_cpdlog_review', '', null, $url);

$queueurl = new moodle_url('/local/cpdlog/admin/review.php');
$entry = new entry($id);
if (!review_manager::can_review($entry, (int) $USER->id)) {
    throw new moodle_exception('error:entrynotreviewable', 'local_cpdlog', $queueurl);
}

$form = new reject_form($url);
if ($form->is_cancelled()) {
    redirect($queueurl);
}
if ($data = $form->get_data()) {
    review_manager::reject($entry, (int) $USER->id, $data->reason);
    redirect($queueurl, get_string('entryrejected', 'local_cpdlog'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$category = category::get_record(['id' => $entry->get('categoryid')]);
$details = new html_table();
$details->attributes['class'] = 'generaltable local-cpdlog-rejectentry';
$details->data = [
    [get_string('member', 'local_cpdlog'), s(fullname(core_user::get_user($entry->get('userid'), '*', MUST_EXIST)))],
    [get_string('activitydate', 'local_cpdlog'), display::activity_date($entry)],
    [get_string('category'), $category ? format_string($category->get('name')) : ''],
    [get_string('course'), format_string((string) $entry->get('coursename'))],
    [get_string('hours', 'local_cpdlog'), format_float($entry->get('hours'), 2)],
    [get_string('evidence', 'local_cpdlog'), display::evidence_links($entry)],
];

$heading = get_string('rejectentry', 'local_cpdlog');
$PAGE->navbar->add($heading);
echo $OUTPUT->header();
echo $OUTPUT->heading($heading);
echo html_writer::table($details);
$form->display();
echo $OUTPUT->footer();
