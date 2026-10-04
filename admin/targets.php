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
 * Lists the CPD targets of a period and handles deleting them.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_cpdlog\local\target_resolver;
use local_cpdlog\persistent\category;
use local_cpdlog\persistent\period;
use local_cpdlog\persistent\target;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$periodid = required_param('periodid', PARAM_INT);
$url = new moodle_url('/local/cpdlog/admin/targets.php', ['periodid' => $periodid]);

// Checks login and local/cpdlog:manageperiods at system context.
admin_externalpage_setup('local_cpdlog_periods', '', null, $url);

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$period = new period($periodid);
$editable = !$period->is_closed();

if ($action !== '') {
    if ($action !== 'delete') {
        throw new moodle_exception('invalidaction', 'error');
    }
    if (!$editable) {
        throw new moodle_exception('error:periodclosed', 'local_cpdlog', $url);
    }
    $target = new target($id);
    if ((int) $target->get('periodid') !== $periodid) {
        throw new moodle_exception('invalidrecord', 'error', $url, target::TABLE);
    }
    if (!$confirm) {
        $confirmurl = new moodle_url($url, ['action' => 'delete', 'id' => $id, 'confirm' => 1]);
        $message = get_string('confirmdeletetarget', 'local_cpdlog', format_string($target->get('name')));
        echo $OUTPUT->header();
        echo $OUTPUT->confirm($message, $confirmurl, $url);
        echo $OUTPUT->footer();
        die();
    }
    require_sesskey();
    $transaction = $DB->start_delegated_transaction();
    $target->delete();
    $transaction->allow_commit();
    redirect($url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$categorynames = [];
foreach (category::get_records([], 'sortorder') as $category) {
    $categorynames[$category->get('id')] = format_string($category->get('name'));
}
$cohortnames = $DB->get_records_menu('cohort', null, '', 'id, name');

$table = new html_table();
$table->head = [
    get_string('name'),
    get_string('cohort', 'cohort'),
    get_string('targetcategories', 'local_cpdlog'),
    get_string('requiredhours', 'local_cpdlog'),
];
if ($editable) {
    $table->head[] = get_string('actions');
}
$table->attributes['class'] = 'generaltable local-cpdlog-targets';

foreach (target::get_records(['periodid' => $periodid], 'sortorder, name') as $target) {
    $cohortid = $target->get('cohortid');
    $categories = array_map(fn($categoryid) => $categorynames[$categoryid] ?? '', $target->get_categoryids());
    $row = [
        format_string($target->get('name')),
        $cohortid ? format_string($cohortnames[$cohortid] ?? '') : get_string('allmembers', 'local_cpdlog'),
        $categories ? implode(', ', $categories) : get_string('allcategories', 'local_cpdlog'),
        format_float($target->get('requiredhours'), 2),
    ];
    if ($editable) {
        $params = ['periodid' => $periodid, 'id' => $target->get('id')];
        $row[] = $OUTPUT->action_icon(
            new moodle_url('/local/cpdlog/admin/target.php', $params),
            new pix_icon('t/edit', get_string('edit'))
        ) . ' ' . $OUTPUT->action_icon(
            new moodle_url($url, $params + ['action' => 'delete']),
            new pix_icon('t/delete', get_string('delete'))
        );
    }
    $table->data[] = $row;
}

$heading = get_string('targetsfor', 'local_cpdlog', format_string($period->get('name')));
$PAGE->navbar->add($heading);
echo $OUTPUT->header();
echo $OUTPUT->heading($heading);
echo html_writer::tag('p', get_string('targets_desc', 'local_cpdlog'));
if ($editable) {
    echo $OUTPUT->single_button(
        new moodle_url('/local/cpdlog/admin/target.php', ['periodid' => $periodid]),
        get_string('addtarget', 'local_cpdlog'),
        'get'
    );
} else {
    echo $OUTPUT->notification(get_string('periodclosedtargets', 'local_cpdlog'), 'info');
}
echo html_writer::table($table);
$unresolved = count(array_filter(target_resolver::get_conflicts($periodid), fn($conflict) => !$conflict->resolved));
echo $OUTPUT->single_button(
    new moodle_url('/local/cpdlog/admin/conflicts.php', ['periodid' => $periodid]),
    get_string('cohortconflicts', 'local_cpdlog', $unresolved),
    'get'
);
echo $OUTPUT->single_button(new moodle_url('/local/cpdlog/admin/periods.php'), get_string('backtoperiods', 'local_cpdlog'), 'get');
echo $OUTPUT->footer();
