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
 * Shows how approved CPD entries are being sent to iMIS, and sends them again.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_cpdlog\local\display;
use local_cpdlog\local\push\payload;
use local_cpdlog\local\push\pusher;
use local_cpdlog\persistent\category;
use local_cpdlog\persistent\entry;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Checks login and local/cpdlog:sync at system context.
admin_externalpage_setup('local_cpdlog_push');

$status = optional_param('status', pusher::STATUS_FAILED, PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$statuses = [pusher::STATUS_FAILED, pusher::STATUS_SKIPPED, pusher::STATUS_DRYRUN, pusher::STATUS_PENDING, pusher::STATUS_SENT];
if (!in_array($status, $statuses, true)) {
    $status = pusher::STATUS_FAILED;
}
$url = new moodle_url('/local/cpdlog/admin/push.php', ['status' => $status]);
$PAGE->set_url($url);

// Sending one entry again, or every entry shown.
$entryid = optional_param('entryid', 0, PARAM_INT);
$all = optional_param('all', 0, PARAM_BOOL);
if ($entryid || $all) {
    require_sesskey();
    $ids = $entryid ? [$entryid] : $DB->get_fieldset_select(pusher::TABLE, 'entryid', 'status = :status', ['status' => $status]);
    $queued = 0;
    foreach ($ids as $id) {
        $queued += pusher::queue((int) $id) ? 1 : 0;
    }
    redirect($url, get_string('push:requeued', 'local_cpdlog', $queued), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('push', 'local_cpdlog'));
echo html_writer::tag('p', get_string('push_desc', 'local_cpdlog'));
$settingsurl = (new moodle_url('/admin/settings.php', ['section' => 'local_cpdlog_pushsettings']))->out();
if (!pusher::is_enabled()) {
    echo $OUTPUT->notification(get_string('push:off', 'local_cpdlog', $settingsurl), 'info');
} else if (pusher::is_dry_run()) {
    echo $OUTPUT->notification(get_string('push:dryrunon', 'local_cpdlog', $settingsurl), 'warning');
}

$counts = $DB->get_records_sql_menu('SELECT status, COUNT(1) FROM {' . pusher::TABLE . '} GROUP BY status');
$tabs = [];
foreach ($statuses as $name) {
    $label = get_string('push:status:' . $name, 'local_cpdlog') . ' (' . (int) ($counts[$name] ?? 0) . ')';
    $tabs[] = new tabobject($name, new moodle_url($url, ['status' => $name]), $label);
}
echo $OUTPUT->tabtree($tabs, $status);

$perpage = 50;
$total = $DB->count_records(pusher::TABLE, ['status' => $status]);
$states = $DB->get_records(pusher::TABLE, ['status' => $status], 'timemodified DESC, id DESC', '*', $page * $perpage, $perpage);
$categories = [];
foreach (category::get_records() as $category) {
    $categories[$category->get('id')] = format_string($category->get('name'));
}
$dateformat = get_string('strftimedatefull', 'local_cpdlog');
$table = new html_table();
$table->attributes['class'] = 'generaltable local-cpdlog-push';
$table->head = [
    get_string('user'),
    get_string('activitydate', 'local_cpdlog'),
    get_string('import:title', 'local_cpdlog'),
    get_string('category'),
    get_string('hours', 'local_cpdlog'),
    get_string('push:action', 'local_cpdlog'),
    get_string('push:imiskey', 'local_cpdlog'),
    get_string('push:detail', 'local_cpdlog'),
    get_string('actions'),
];
foreach ($states as $state) {
    $entry = entry::get_record(['id' => $state->entryid]);
    if (!$entry) {
        continue;
    }
    $user = core_user::get_user($entry->get('userid'));
    $detail = $state->error === null ? '' : html_writer::div(s($state->error), 'small');
    if ($state->timesent) {
        $sent = userdate($state->timesent, get_string('strftimedatetimefull', 'local_cpdlog'));
        $detail .= html_writer::div($sent, 'small text-muted');
    }
    if ($state->payload) {
        $detail .= html_writer::tag('details', html_writer::tag('summary', get_string('push:payload', 'local_cpdlog'))
            . html_writer::tag('pre', s($state->payload), ['class' => 'small']));
    }
    $action = '';
    if ($status !== pusher::STATUS_PENDING) {
        $action = $OUTPUT->single_button(
            new moodle_url($url, ['entryid' => $state->entryid, 'sesskey' => sesskey()]),
            get_string('push:sendagain', 'local_cpdlog'),
            'post'
        );
    }
    $table->data[] = [
        $user ? html_writer::link(new moodle_url('/user/profile.php', ['id' => $user->id]), fullname($user)) : '',
        userdate($entry->get('activitydate'), $dateformat, core_date::get_server_timezone(), false),
        display::course($entry),
        $categories[$entry->get('categoryid')] ?? '',
        format_float($entry->get('hours'), 2),
        get_string('push:action:' . ($state->action ?: 'create'), 'local_cpdlog'),
        s((string) $state->imiskey),
        $detail,
        $action,
    ];
}
if ($table->data) {
    if ($status !== pusher::STATUS_PENDING && $status !== pusher::STATUS_SENT) {
        echo $OUTPUT->single_button(
            new moodle_url($url, ['all' => 1, 'sesskey' => sesskey()]),
            get_string('push:sendallagain', 'local_cpdlog', $total),
            'post'
        );
    }
    echo html_writer::table($table);
    echo $OUTPUT->paging_bar($total, $page, $perpage, $url);
} else {
    echo html_writer::tag('p', get_string('push:none', 'local_cpdlog'));
}
echo html_writer::tag('p', get_string('push:target', 'local_cpdlog', s(payload::entity())), ['class' => 'text-muted small']);
echo $OUTPUT->footer();
