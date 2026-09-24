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
 * A member's CPD logbook: their entries, with submit and delete for drafts.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

use local_cpdlog\local\entry_manager;
use local_cpdlog\persistent\category;
use local_cpdlog\persistent\entry;

require(__DIR__ . '/../../config.php');

require_login(null, false);
$context = context_system::instance();
require_capability('local/cpdlog:viewown', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$url = new moodle_url('/local/cpdlog/index.php');

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('mylogbook', 'local_cpdlog'));
$PAGE->set_heading(get_string('mylogbook', 'local_cpdlog'));

if ($action !== '') {
    require_capability('local/cpdlog:submit', $context);
    if (!in_array($action, ['submit', 'delete'], true)) {
        throw new moodle_exception('invalidaction', 'error');
    }
    // Ownership and state are checked by the entry manager, never taken from the request.
    $entry = new entry($id);
    if (!entry_manager::can_edit($entry, (int) $USER->id) || $entry->get('status') !== entry::STATUS_DRAFT) {
        throw new moodle_exception('error:entrylocked', 'local_cpdlog', $url);
    }
    if (!$confirm) {
        $confirmurl = new moodle_url($url, ['action' => $action, 'id' => $id, 'confirm' => 1]);
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(get_string('confirm' . $action . 'entry', 'local_cpdlog'), $confirmurl, $url);
        echo $OUTPUT->footer();
        die();
    }
    require_sesskey();
    if ($action === 'submit') {
        entry_manager::submit($entry, (int) $USER->id);
        redirect($url, get_string('entrysubmitted', 'local_cpdlog'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    entry_manager::delete_draft($entry, (int) $USER->id);
    redirect($url, get_string('entrydeleted', 'local_cpdlog'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$cansubmit = has_capability('local/cpdlog:submit', $context);
$timezone = core_date::get_server_timezone();
$dateformat = get_string('strftimedatefull', 'local_cpdlog');
$categorynames = [];
foreach (category::get_records() as $category) {
    $categorynames[$category->get('id')] = format_string($category->get('name'));
}

$table = new html_table();
$table->head = [
    get_string('activitydate', 'local_cpdlog'),
    get_string('category'),
    get_string('course'),
    get_string('hours', 'local_cpdlog'),
    get_string('status'),
    get_string('actions'),
];
$table->attributes['class'] = 'generaltable local-cpdlog-entries';

// Newest first. get_records() would append its own sort order, so the sort is passed whole here.
foreach (entry::get_records_select('userid = :userid', ['userid' => $USER->id], 'activitydate DESC, id DESC') as $entry) {
    $entryid = $entry->get('id');
    $status = get_string('entrystatus:' . $entry->get('status'), 'local_cpdlog');
    if (!$entry->is_moodle_owned()) {
        $status .= ' ' . html_writer::span(get_string('fromimis', 'local_cpdlog'), 'badge bg-secondary');
    }
    if ($entry->get('rejectionreason') !== null && $entry->get('rejectionreason') !== '') {
        $status .= html_writer::div(get_string('rejectionreasonis', 'local_cpdlog', s($entry->get('rejectionreason'))), 'small');
    }

    $actions = [];
    if ($cansubmit && entry_manager::can_edit($entry, (int) $USER->id)) {
        $actions[] = $OUTPUT->action_icon(
            new moodle_url('/local/cpdlog/edit.php', ['id' => $entryid]),
            new pix_icon('t/edit', get_string('edit'))
        );
        if ($entry->get('status') === entry::STATUS_DRAFT) {
            $actions[] = $OUTPUT->action_icon(
                new moodle_url($url, ['action' => 'submit', 'id' => $entryid]),
                new pix_icon('t/approve', get_string('submit'))
            );
            $actions[] = $OUTPUT->action_icon(
                new moodle_url($url, ['action' => 'delete', 'id' => $entryid]),
                new pix_icon('t/delete', get_string('delete'))
            );
        }
    }

    $table->data[] = [
        userdate($entry->get('activitydate'), $dateformat, $timezone, false),
        $categorynames[$entry->get('categoryid')] ?? '',
        format_string((string) $entry->get('coursename')),
        format_float($entry->get('hours'), 2),
        $status,
        implode(' ', $actions),
    ];
}

echo $OUTPUT->header();
if ($cansubmit) {
    echo $OUTPUT->single_button(new moodle_url('/local/cpdlog/edit.php'), get_string('addentry', 'local_cpdlog'), 'get');
}
if ($table->data) {
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('noentries', 'local_cpdlog'), 'info');
}
echo $OUTPUT->footer();
