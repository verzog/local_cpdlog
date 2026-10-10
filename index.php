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
 * A member's CPD logbook: their progress against targets, and their entries, with submit and delete for drafts.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_cpdlog\local\display;
use local_cpdlog\local\entry_manager;
use local_cpdlog\persistent\category;
use local_cpdlog\persistent\entry;
use local_cpdlog\persistent\period;

require(__DIR__ . '/../../config.php');

require_login(null, false);
$context = context_system::instance();
require_capability('local/cpdlog:viewown', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$periodid = optional_param('periodid', 0, PARAM_INT);
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
    if ($action === 'submit' && entry_manager::is_missing_evidence($entry)) {
        throw new moodle_exception('error:evidencerequired', 'local_cpdlog', $url);
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
    get_string('evidence', 'local_cpdlog'),
    get_string('status'),
    get_string('actions'),
];
$table->attributes['class'] = 'generaltable local-cpdlog-entries';

// Newest first. get_records() would append its own sort order, so the sort is passed whole here.
foreach (entry::get_records_select('userid = :userid', ['userid' => $USER->id], 'activitydate DESC, id DESC') as $entry) {
    $entryid = $entry->get('id');
    $status = get_string('entrystatus:' . $entry->get('status'), 'local_cpdlog');
    if (!$entry->is_moodle_owned()) {
        $from = get_string('from' . $entry->get('source'), 'local_cpdlog');
        $status .= ' ' . html_writer::span($from, 'badge bg-secondary');
    }
    if ($entry->get('status') === entry::STATUS_REJECTED) {
        $status .= html_writer::div(get_string('rejectionreasonis', 'local_cpdlog', s($entry->get('rejectionreason'))), 'small');
    }
    if ($entry->get('status') === entry::STATUS_REVERSED) {
        $status .= html_writer::div(get_string('reversalreasonis', 'local_cpdlog', s($entry->get('reversalreason'))), 'small');
    }

    $actions = [];
    if ($cansubmit && entry_manager::can_edit($entry, (int) $USER->id)) {
        $actions[] = $OUTPUT->action_icon(
            new moodle_url('/local/cpdlog/edit.php', ['id' => $entryid]),
            new pix_icon('t/edit', get_string('edit'))
        );
        if ($entry->get('status') === entry::STATUS_DRAFT && entry_manager::is_missing_evidence($entry)) {
            $status .= html_writer::div(get_string('evidenceneeded', 'local_cpdlog'), 'small');
        } else if ($entry->get('status') === entry::STATUS_DRAFT) {
            $actions[] = $OUTPUT->action_icon(
                new moodle_url($url, ['action' => 'submit', 'id' => $entryid]),
                new pix_icon('t/approve', get_string('submit'))
            );
        }
        if ($entry->get('status') === entry::STATUS_DRAFT) {
            $actions[] = $OUTPUT->action_icon(
                new moodle_url($url, ['action' => 'delete', 'id' => $entryid]),
                new pix_icon('t/delete', get_string('delete'))
            );
        }
    }

    $table->data[] = [
        display::activity_date($entry),
        $categorynames[$entry->get('categoryid')] ?? '',
        display::course($entry),
        format_float($entry->get('hours'), 2),
        display::evidence_links($entry),
        $status,
        implode(' ', $actions),
    ];
}

// Progress is shown for the chosen period; by default the current one, or else the latest to have started.
$periods = period::get_records([], 'startdate', 'DESC');
$shown = null;
foreach ($periods as $period) {
    if ((int) $period->get('id') === $periodid) {
        $shown = $period;
    }
}
if (!$shown && $periods) {
    $started = array_filter($periods, fn(period $period) => $period->get('startdate') <= time());
    $shown = entry_manager::find_period(time()) ?? (reset($started) ?: reset($periods));
}

echo $OUTPUT->header();
if ($shown) {
    $options = [];
    foreach ($periods as $period) {
        $options[$period->get('id')] = format_string($period->get('name'));
    }
    $select = new single_select($url, 'periodid', $options, $shown->get('id'), null);
    $select->set_label(get_string('reportingperiod', 'local_cpdlog'));
    echo $OUTPUT->render($select);
    echo $OUTPUT->render_from_template('local_cpdlog/progress', display::progress((int) $USER->id, $shown));
}
if ($cansubmit) {
    echo $OUTPUT->single_button(new moodle_url('/local/cpdlog/edit.php'), get_string('addentry', 'local_cpdlog'), 'get');
}
if ($table->data) {
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('noentries', 'local_cpdlog'), 'info');
}
echo $OUTPUT->footer();
