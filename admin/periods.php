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
 * Lists CPD reporting periods and handles close, reopen and delete actions.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

use local_cpdlog\persistent\period;
use local_cpdlog\persistent\target;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Checks login and local/cpdlog:manageperiods at system context.
admin_externalpage_setup('local_cpdlog_periods');

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$url = new moodle_url('/local/cpdlog/admin/periods.php');
$timezone = core_date::get_server_timezone();
$dateformat = get_string('strftimedatefull', 'local_cpdlog');

if ($action !== '') {
    $period = new period($id);
    $name = format_string($period->get('name'));
    if (!in_array($action, ['close', 'reopen', 'delete'], true)) {
        throw new moodle_exception('invalidaction', 'error');
    }
    if ($action === 'delete' && !$period->can_delete()) {
        throw new moodle_exception('error:periodinuse', 'local_cpdlog');
    }
    if (!$confirm) {
        $confirmurl = new moodle_url($url, ['action' => $action, 'id' => $id, 'confirm' => 1]);
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(get_string('confirm' . $action . 'period', 'local_cpdlog', $name), $confirmurl, $url);
        echo $OUTPUT->footer();
        die();
    }
    require_sesskey();
    if ($action === 'delete') {
        $period->delete();
    } else {
        $period->set('status', $action === 'close' ? period::STATUS_CLOSED : period::STATUS_OPEN);
        $period->update();
    }
    redirect($url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$table = new html_table();
$table->head = [
    get_string('name'),
    get_string('firstday', 'local_cpdlog'),
    get_string('lastday', 'local_cpdlog'),
    get_string('status'),
    get_string('targets', 'local_cpdlog'),
    get_string('actions'),
];
$table->attributes['class'] = 'generaltable local-cpdlog-periods';

foreach (period::get_records([], 'startdate') as $period) {
    $id = $period->get('id');
    $actionurl = fn(string $action) => new moodle_url($url, ['action' => $action, 'id' => $id]);
    $targetsurl = new moodle_url('/local/cpdlog/admin/targets.php', ['periodid' => $id]);

    $actions = [];
    if ($period->is_closed()) {
        $actions[] = $OUTPUT->action_icon($actionurl('reopen'), new pix_icon('t/unlock', get_string('reopen', 'local_cpdlog')));
    } else {
        $actions[] = $OUTPUT->action_icon(
            new moodle_url('/local/cpdlog/admin/period.php', ['id' => $id]),
            new pix_icon('t/edit', get_string('edit'))
        );
        $actions[] = $OUTPUT->action_icon($actionurl('close'), new pix_icon('t/lock', get_string('close', 'local_cpdlog')));
    }
    if ($period->can_delete()) {
        $actions[] = $OUTPUT->action_icon($actionurl('delete'), new pix_icon('t/delete', get_string('delete')));
    }

    $table->data[] = [
        format_string($period->get('name')),
        userdate($period->get('startdate'), $dateformat, $timezone, false),
        userdate($period->get_lastday(), $dateformat, $timezone, false),
        get_string('status' . $period->get('status'), 'local_cpdlog'),
        html_writer::link($targetsurl, target::count_records(['periodid' => $id])),
        implode(' ', $actions),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('periods', 'local_cpdlog'));
echo html_writer::tag('p', get_string('periods_desc', 'local_cpdlog'));
echo $OUTPUT->single_button(new moodle_url('/local/cpdlog/admin/period.php'), get_string('addperiod', 'local_cpdlog'), 'get');
echo html_writer::table($table);
echo $OUTPUT->footer();
