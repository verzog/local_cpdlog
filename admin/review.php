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
 * Approval queue: entries members have submitted, for staff to approve or reject.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

use local_cpdlog\local\display;
use local_cpdlog\local\review_manager;
use local_cpdlog\persistent\category;
use local_cpdlog\persistent\entry;
use local_cpdlog\persistent\period;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Checks login and local/cpdlog:approve at system context.
admin_externalpage_setup('local_cpdlog_review');

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$page = optional_param('page', 0, PARAM_INT);
$url = new moodle_url('/local/cpdlog/admin/review.php');
$reviewerid = (int) $USER->id;

if ($action === 'approve') {
    // State and ownership are checked by the review manager, never taken from the request.
    $entry = new entry($id);
    if (!review_manager::can_review($entry, $reviewerid)) {
        throw new moodle_exception('error:entrynotreviewable', 'local_cpdlog', $url);
    }
    if (!$confirm) {
        $a = (object) [
            'member' => s(fullname(core_user::get_user($entry->get('userid'), '*', MUST_EXIST))),
            'date' => display::activity_date($entry),
        ];
        $confirmurl = new moodle_url($url, ['action' => 'approve', 'id' => $id, 'confirm' => 1]);
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(get_string('confirmapproveentry', 'local_cpdlog', $a), $confirmurl, $url);
        echo $OUTPUT->footer();
        die();
    }
    require_sesskey();
    review_manager::approve($entry, $reviewerid);
    redirect($url, get_string('entryapproved', 'local_cpdlog'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'bulkapprove') {
    // The queue posts the ticked boxes; the confirmation posts them back as one list.
    if ($confirm) {
        $ids = explode(',', required_param('idlist', PARAM_SEQUENCE));
    } else {
        $ids = optional_param_array('ids', [], PARAM_INT);
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        redirect($url, get_string('error:nothingselected', 'local_cpdlog'), null, \core\output\notification::NOTIFY_WARNING);
    }
    if (count($ids) > review_manager::BULK_LIMIT) {
        throw new moodle_exception('error:bulklimit', 'local_cpdlog', $url, review_manager::BULK_LIMIT);
    }
    if (!$confirm) {
        $confirmurl = new moodle_url($url, ['action' => 'bulkapprove', 'idlist' => implode(',', $ids), 'confirm' => 1]);
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(get_string('confirmbulkapprove', 'local_cpdlog', count($ids)), $confirmurl, $url);
        echo $OUTPUT->footer();
        die();
    }
    require_sesskey();
    $a = (object) ['approved' => review_manager::approve_many($ids, $reviewerid)];
    $a->skipped = count($ids) - $a->approved;
    $message = get_string($a->skipped ? 'bulkapprovedskipped' : 'bulkapproved', 'local_cpdlog', $a);
    redirect($url, $message, null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action !== '') {
    throw new moodle_exception('invalidaction', 'error');
}

// Entries owned by iMIS are never reviewed here, so they are left out.
$select = 'status = :status AND source = :source';
$params = ['status' => entry::STATUS_SUBMITTED, 'source' => entry::SOURCE_MOODLE];
$total = entry::count_records_select($select, $params);
// Oldest submission first, so nothing waits indefinitely.
$entries = entry::get_records_select(
    $select,
    $params,
    'timesubmitted ASC, id ASC',
    '*',
    $page * review_manager::BULK_LIMIT,
    review_manager::BULK_LIMIT
);

$members = [];
if ($entries) {
    [$insql, $inparams] = $DB->get_in_or_equal(array_map(fn(entry $entry) => $entry->get('userid'), $entries), SQL_PARAMS_NAMED);
    $names = \core_user\fields::for_name()->get_sql('u', true);
    $members = $DB->get_records_sql("SELECT u.id {$names->selects} FROM {user} u WHERE u.id {$insql}", $inparams + $names->params);
}
$categorynames = [];
foreach (category::get_records() as $category) {
    $categorynames[$category->get('id')] = format_string($category->get('name'));
}
$closedperiods = [];
foreach (period::get_records(['status' => period::STATUS_CLOSED]) as $period) {
    $closedperiods[$period->get('id')] = true;
}

$submittedformat = get_string('strftimedatetimeshort', 'langconfig');

$table = new html_table();
$table->head = [
    get_string('select'),
    get_string('member', 'local_cpdlog'),
    get_string('timesubmitted', 'local_cpdlog'),
    get_string('activitydate', 'local_cpdlog'),
    get_string('category'),
    get_string('course'),
    get_string('hours', 'local_cpdlog'),
    get_string('description'),
    get_string('evidence', 'local_cpdlog'),
    get_string('actions'),
];
$table->attributes['class'] = 'generaltable local-cpdlog-review';
$canselect = false;

foreach ($entries as $entry) {
    $entryid = $entry->get('id');
    $member = $members[$entry->get('userid')] ?? null;
    $membername = $member ? fullname($member) : '';

    $checkbox = '';
    $actions = '';
    if ((int) $entry->get('userid') === $reviewerid) {
        $actions = html_writer::div(get_string('ownentry', 'local_cpdlog'), 'small');
    } else if (isset($closedperiods[$entry->get('periodid')])) {
        $actions = html_writer::div(get_string('periodclosedreview', 'local_cpdlog'), 'small');
    } else if (review_manager::can_review($entry, $reviewerid)) {
        $canselect = true;
        $a = (object) ['member' => s($membername), 'date' => display::activity_date($entry)];
        $checkbox = html_writer::checkbox(
            'ids[]',
            $entryid,
            false,
            get_string('selectentry', 'local_cpdlog', $a),
            ['id' => 'local-cpdlog-select-' . $entryid],
            ['class' => 'visually-hidden']
        );
        $actions = $OUTPUT->action_icon(
            new moodle_url($url, ['action' => 'approve', 'id' => $entryid]),
            new pix_icon('t/approve', get_string('approve'))
        ) . ' ' . $OUTPUT->action_icon(
            new moodle_url('/local/cpdlog/admin/reject.php', ['id' => $entryid]),
            new pix_icon('t/block', get_string('reject'))
        );
    }

    $table->data[] = [
        $checkbox,
        $member ? html_writer::link(new moodle_url('/user/profile.php', ['id' => $member->id]), s($membername)) : '',
        $entry->get('timesubmitted') ? userdate($entry->get('timesubmitted'), $submittedformat) : '',
        display::activity_date($entry),
        $categorynames[$entry->get('categoryid')] ?? '',
        format_string((string) $entry->get('coursename')),
        format_float($entry->get('hours'), 2),
        display::description($entry),
        display::evidence_links($entry),
        $actions,
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('approvalqueue', 'local_cpdlog'));
echo html_writer::tag('p', get_string('approvalqueue_desc', 'local_cpdlog'));
if (!$table->data) {
    echo $OUTPUT->notification(get_string('noentriestoreview', 'local_cpdlog'), 'info');
    echo $OUTPUT->footer();
    die();
}

// Explicit file name: a bare directory URL can be refused by the web server.
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'bulkapprove']);
echo html_writer::table($table);
if ($canselect) {
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-primary',
        'value' => get_string('approveselected', 'local_cpdlog'),
    ]);
}
echo html_writer::end_tag('form');
echo $OUTPUT->paging_bar($total, $page, review_manager::BULK_LIMIT, $url);
echo $OUTPUT->footer();
