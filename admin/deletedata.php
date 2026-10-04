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
 * Deletes a member's CPD data, one member at a time, and lists past deletions.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

use local_cpdlog\form\deletedata_confirm_form;
use local_cpdlog\form\deletedata_find_form;
use local_cpdlog\local\data_deleter;
use local_cpdlog\persistent\entry;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Checks login and local/cpdlog:deletedata at system context.
admin_externalpage_setup('local_cpdlog_deletedata');

$userid = optional_param('userid', 0, PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);
$url = new moodle_url('/local/cpdlog/admin/deletedata.php');
$enabled = data_deleter::is_enabled();

if ($userid) {
    $user = core_user::get_user($userid, '*', MUST_EXIST);
    if (isguestuser($user)) {
        throw new moodle_exception('invaliduser', 'error', $url);
    }
    $memberurl = new moodle_url($url, ['userid' => $userid]);
    $queued = data_deleter::get_queued($userid);

    // The confirmation form posts to this page with the member's id; moodleform checks the session key.
    $form = new deletedata_confirm_form($memberurl, ['user' => $user]);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($enabled && !$queued && $form->get_data()) {
        data_deleter::queue($userid, (int) $USER->id);
        $message = get_string('deletedata:queued', 'local_cpdlog', s(fullname($user)));
        redirect($url, $message, null, \core\output\notification::NOTIFY_SUCCESS);
    }

    $counts = data_deleter::count($userid);
    $items = [];
    foreach (entry::STATUSES as $status) {
        $a = (object) [
            'count' => $counts->entries[$status] ?? 0,
            'status' => get_string('entrystatus:' . $status, 'local_cpdlog'),
        ];
        $items[] = get_string('deletedata:countstatus', 'local_cpdlog', $a);
    }
    $items[] = get_string('deletedata:countfiles', 'local_cpdlog', $counts->files);
    $items[] = get_string('deletedata:countchoices', 'local_cpdlog', $counts->choices);
    $items[] = get_string('deletedata:counttraces', 'local_cpdlog', $counts->stafftraces);

    $heading = get_string('deletedata:for', 'local_cpdlog', s(fullname($user)));
    $PAGE->navbar->add($heading);
    echo $OUTPUT->header();
    echo $OUTPUT->heading($heading);
    if ($user->deleted) {
        echo html_writer::tag('p', get_string('deletedata:identitydeleted', 'local_cpdlog', $user->id));
    } else {
        echo html_writer::tag('p', get_string('deletedata:identity', 'local_cpdlog', (object) [
            'username' => s($user->username),
            'email' => s($user->email),
        ]));
    }
    echo html_writer::tag('p', get_string('deletedata:willremove', 'local_cpdlog'));
    echo html_writer::alist($items);
    if ($queued) {
        echo $OUTPUT->notification(get_string('error:deletionqueued', 'local_cpdlog'), 'info');
    } else if (!$enabled) {
        echo $OUTPUT->notification(get_string('error:deletiondisabled', 'local_cpdlog'), 'warning');
    } else {
        echo $OUTPUT->notification(get_string('deletedata:warning', 'local_cpdlog'), 'warning');
        $form->display();
    }
    echo $OUTPUT->footer();
    die();
}

$findform = new deletedata_find_form($url);
if ($enabled && ($data = $findform->get_data())) {
    $user = deletedata_find_form::find_user($data->member, !empty($data->deletedaccount));
    redirect(new moodle_url($url, ['userid' => $user->id]));
}

// The register of past deletions, newest first.
$total = $DB->count_records(data_deleter::TABLE);
$names = \core_user\fields::for_name();
$membernames = $names->get_sql('m', true, 'member');
$staffnames = $names->get_sql('s', true, 'staff');
$sql = "SELECT d.* {$membernames->selects} {$staffnames->selects}
          FROM {" . data_deleter::TABLE . "} d
          JOIN {user} m ON m.id = d.userid
          JOIN {user} s ON s.id = d.requestedby
      ORDER BY d.timerequested DESC, d.id DESC";
$records = $DB->get_records_sql($sql, $membernames->params + $staffnames->params, $page * 50, 50);
$timeformat = get_string('strftimedatetimeshort', 'langconfig');

$table = new html_table();
$table->head = [
    get_string('member', 'local_cpdlog'),
    get_string('deletedata:requestedby', 'local_cpdlog'),
    get_string('deletedata:requested', 'local_cpdlog'),
    get_string('status'),
    get_string('deletedata:entriesdeleted', 'local_cpdlog'),
    get_string('deletedata:filesdeleted', 'local_cpdlog'),
    get_string('deletedata:completed', 'local_cpdlog'),
];
$table->attributes['class'] = 'generaltable local-cpdlog-deletions';
foreach ($records as $record) {
    $table->data[] = [
        s(fullname(username_load_fields_from_object(new stdClass(), $record, 'member'))),
        s(fullname(username_load_fields_from_object(new stdClass(), $record, 'staff'))),
        userdate($record->timerequested, $timeformat),
        get_string('deletionstatus:' . $record->status, 'local_cpdlog'),
        $record->status === data_deleter::STATUS_DONE ? $record->entriesdeleted : '',
        $record->status === data_deleter::STATUS_DONE ? $record->filesdeleted : '',
        $record->timecompleted ? userdate($record->timecompleted, $timeformat) : '',
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('deletedata', 'local_cpdlog'));
echo html_writer::tag('p', get_string('deletedata_desc', 'local_cpdlog'));
if ($enabled) {
    $findform->display();
} else {
    echo $OUTPUT->notification(get_string('error:deletiondisabled', 'local_cpdlog'), 'warning');
}
echo $OUTPUT->heading(get_string('deletedata:register', 'local_cpdlog'), 3);
if ($table->data) {
    echo html_writer::table($table);
    echo $OUTPUT->paging_bar($total, $page, 50, $url);
} else {
    echo html_writer::tag('p', get_string('deletedata:noregister', 'local_cpdlog'));
}
echo $OUTPUT->footer();
