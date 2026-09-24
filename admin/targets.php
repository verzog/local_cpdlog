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
 * Lists the CPD targets of a period and handles deleting them.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

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
echo $OUTPUT->single_button(new moodle_url('/local/cpdlog/admin/periods.php'), get_string('backtoperiods', 'local_cpdlog'), 'get');
echo $OUTPUT->footer();
