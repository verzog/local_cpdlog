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
 * Lists imported CPD history that looks like entries already in the logbook, for staff to decide.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_cpdlog\local\display;
use local_cpdlog\local\import\auditor;
use local_cpdlog\local\import\importer;
use local_cpdlog\persistent\category;
use local_cpdlog\persistent\entry;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Checks login and local/cpdlog:sync at system context.
admin_externalpage_setup('local_cpdlog_import', '', null, new moodle_url('/local/cpdlog/admin/importconflicts.php'));
$url = new moodle_url('/local/cpdlog/admin/importconflicts.php');
$PAGE->set_url($url);
$PAGE->navbar->add(get_string('import:conflicts', 'local_cpdlog'));

$rowid = optional_param('rowid', 0, PARAM_INT);
$decision = optional_param('decision', '', PARAM_ALPHA);
if ($rowid && $decision !== '') {
    require_sesskey();
    $imported = importer::decide($rowid, $decision, (int) $USER->id);
    $message = get_string($imported ? 'import:decidedimport' : 'import:decidedskip', 'local_cpdlog');
    redirect($url, $message, null, \core\output\notification::NOTIFY_SUCCESS);
}

// Only the latest run of each source counts: earlier runs hold older copies of the same rows.
$latest = $DB->get_records_sql_menu('SELECT source, MAX(id) FROM {' . auditor::RUN_TABLE . '} GROUP BY source');
$rows = [];
if ($latest) {
    [$insql, $params] = $DB->get_in_or_equal(array_values($latest), SQL_PARAMS_NAMED);
    $rows = $DB->get_records_select(
        auditor::ROW_TABLE,
        "runid {$insql} AND outcome = :conflict AND decision IS NULL",
        $params + ['conflict' => 'conflict'],
        'memberid, activitydate, id'
    );
}
$runsources = $DB->get_records_menu(auditor::RUN_TABLE, null, '', 'id, source');
$categories = [];
foreach (category::get_records() as $category) {
    $categories[$category->get('id')] = format_string($category->get('name'));
}
$userids = array_filter(array_unique(array_column($rows, 'userid')));
$users = $userids ? $DB->get_records_list('user', 'id', $userids) : [];
$dateformat = get_string('strftimedatefull', 'local_cpdlog');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('import:conflicts', 'local_cpdlog'));
echo html_writer::tag('p', get_string('import:conflicts_desc', 'local_cpdlog'));

$table = new html_table();
$table->attributes['class'] = 'generaltable local-cpdlog-importconflicts';
$table->head = [
    get_string('user'),
    get_string('activitydate', 'local_cpdlog'),
    get_string('category'),
    get_string('hours', 'local_cpdlog'),
    get_string('import:incoming', 'local_cpdlog'),
    get_string('import:existingentry', 'local_cpdlog'),
    get_string('actions'),
];
foreach ($rows as $row) {
    $user = $users[$row->userid] ?? null;
    $existing = $row->matchentryid ? entry::get_record(['id' => $row->matchentryid]) : null;
    $actions = '';
    foreach (['import' => 'import:importanyway', 'skip' => 'import:skip'] as $value => $label) {
        $actions .= $OUTPUT->single_button(
            new moodle_url($url, ['rowid' => $row->id, 'decision' => $value, 'sesskey' => sesskey()]),
            get_string($label, 'local_cpdlog'),
            'post'
        );
    }
    $source = get_string('import:source:' . ($runsources[$row->runid] ?? 'csv'), 'local_cpdlog');
    $table->data[] = [
        $user ? html_writer::link(new moodle_url('/user/profile.php', ['id' => $user->id]), fullname($user)) : '',
        userdate($row->activitydate, $dateformat, core_date::get_server_timezone(), false),
        $categories[$row->categoryid] ?? '',
        format_float($row->hours, 2),
        s(trim($row->title . ' (' . $source . ')')),
        $existing ? display::course($existing) . html_writer::div(
            get_string('entrystatus:' . $existing->get('status'), 'local_cpdlog'),
            'small text-muted'
        ) : '',
        $actions,
    ];
}
if ($table->data) {
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('import:noconflicts', 'local_cpdlog'), 'info');
}
echo html_writer::link(new moodle_url('/local/cpdlog/admin/import.php'), get_string('import', 'local_cpdlog'));
echo $OUTPUT->footer();
