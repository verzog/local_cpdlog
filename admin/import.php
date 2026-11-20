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
 * Audits CPD history from iMIS or a CSV file against the logbook, and imports it.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_cpdlog\form\import_audit_form;
use local_cpdlog\local\import\auditor;
use local_cpdlog\local\import\csv_source;
use local_cpdlog\local\import\imis_iqa_source;
use local_cpdlog\local\import\importer;
use local_cpdlog\local\import\mapping;
use local_cpdlog\persistent\category;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Checks login and local/cpdlog:sync at system context.
admin_externalpage_setup('local_cpdlog_import');

$runid = optional_param('runid', 0, PARAM_INT);
$outcome = optional_param('outcome', '', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$url = new moodle_url('/local/cpdlog/admin/import.php');
$PAGE->set_url(new moodle_url($url, array_filter(['runid' => $runid, 'outcome' => $outcome])));

// Importing a run's new rows.
if ($runid && optional_param('import', 0, PARAM_BOOL)) {
    require_sesskey();
    importer::queue($runid, (int) $USER->id);
    redirect(
        new moodle_url($url, ['runid' => $runid]),
        get_string('import:queued', 'local_cpdlog'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$form = new import_audit_form(new moodle_url($url));
$fields = null;
$objectfields = null;
$error = '';
if ($data = $form->get_data()) {
    try {
        if ($data->source === 'csv') {
            $source = new csv_source((string) $form->get_file_content('csvfile'));
        } else {
            $source = new imis_iqa_source();
        }
        if (!empty($data->fields)) {
            $fields = $source->get_fields();
            $object = trim((string) get_config('local_cpdlog', 'importobject'));
            if ($source instanceof imis_iqa_source && $object !== '') {
                try {
                    $objectfields = [$object => $source->get_object_fields($object)];
                } catch (moodle_exception $e) {
                    $objectfields = [$object => []];
                }
            }
        } else {
            $run = auditor::run($source, (string) $data->memberid, (int) $USER->id);
            redirect(new moodle_url($url, ['runid' => $run->id]));
        }
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('import', 'local_cpdlog'));
echo html_writer::tag('p', get_string('import_desc', 'local_cpdlog'));
$settingsurl = new moodle_url('/admin/settings.php', ['section' => 'local_cpdlog_importsettings']);
if ($missing = mapping::get()->get_missing()) {
    echo $OUTPUT->notification(get_string('import:mappingmissing', 'local_cpdlog', $settingsurl->out()), 'warning');
}
if ($error !== '') {
    echo $OUTPUT->notification(s($error), 'error');
}
$form->display();

// The columns the source offers, to help fill in the mapping.
if ($fields !== null) {
    echo $OUTPUT->heading(get_string('import:fields', 'local_cpdlog'), 3);
    $nofields = html_writer::tag('p', get_string('import:nofields', 'local_cpdlog'));
    echo $fields ? html_writer::alist(array_map('s', $fields)) : $nofields;
    foreach ($objectfields ?? [] as $object => $list) {
        echo $OUTPUT->heading(get_string('import:objectfields', 'local_cpdlog', s($object)), 4);
        echo $list ? html_writer::alist(array_map('s', $list)) : $nofields;
    }
}

// One run's results.
if ($runid) {
    $run = $DB->get_record(auditor::RUN_TABLE, ['id' => $runid], '*', MUST_EXIST);
    $counts = (array) json_decode((string) $run->counts);
    $when = userdate($run->timecreated, get_string('strftimedatetimefull', 'local_cpdlog'));
    echo $OUTPUT->heading(get_string('import:run', 'local_cpdlog', $when), 3);
    $tabs = [new tabobject('', new moodle_url($url, ['runid' => $runid]), get_string('all'))];
    foreach (auditor::OUTCOMES as $name) {
        $label = get_string('import:outcome:' . $name, 'local_cpdlog') . ' (' . (int) ($counts[$name] ?? 0) . ')';
        $tabs[] = new tabobject($name, new moodle_url($url, ['runid' => $runid, 'outcome' => $name]), $label);
    }
    echo $OUTPUT->tabtree($tabs, $outcome);

    if ($run->status === 'audited' && !empty($counts['new'])) {
        echo $OUTPUT->single_button(
            new moodle_url($url, ['runid' => $runid, 'import' => 1, 'sesskey' => sesskey()]),
            get_string('import:import', 'local_cpdlog', (int) $counts['new']),
            'post',
            ['primary' => true]
        );
    } else if ($run->status !== 'audited') {
        echo $OUTPUT->notification(get_string('import:status:' . $run->status, 'local_cpdlog'), 'info');
    }

    $conditions = ['runid' => $runid] + ($outcome !== '' ? ['outcome' => $outcome] : []);
    $total = $DB->count_records(auditor::ROW_TABLE, $conditions);
    $perpage = 100;
    $sort = 'outcome, memberid, activitydate, id';
    $rows = $DB->get_records(auditor::ROW_TABLE, $conditions, $sort, '*', $page * $perpage, $perpage);
    $categories = [];
    foreach (category::get_records() as $category) {
        $categories[$category->get('id')] = format_string($category->get('name'));
    }
    $userids = array_filter(array_unique(array_column($rows, 'userid')));
    $users = $userids ? $DB->get_records_list('user', 'id', $userids) : [];

    $dateformat = get_string('strftimedatefull', 'local_cpdlog');
    $table = new html_table();
    $table->attributes['class'] = 'generaltable local-cpdlog-importrows';
    $table->head = [
        get_string('import:memberid', 'local_cpdlog'),
        get_string('user'),
        get_string('activitydate', 'local_cpdlog'),
        get_string('category'),
        get_string('hours', 'local_cpdlog'),
        get_string('import:title', 'local_cpdlog'),
        get_string('import:outcome', 'local_cpdlog'),
    ];
    foreach ($rows as $row) {
        $user = $row->userid ? ($users[$row->userid] ?? null) : null;
        $result = get_string('import:outcome:' . $row->outcome, 'local_cpdlog');
        if ($row->reason) {
            $result .= html_writer::div(get_string('import:reason:' . $row->reason, 'local_cpdlog'), 'small text-muted');
        }
        if ($row->entryid) {
            $result .= html_writer::div(get_string('import:imported', 'local_cpdlog'), 'small text-success');
        }
        $table->data[] = [
            s((string) $row->memberid),
            $user ? html_writer::link(new moodle_url('/user/profile.php', ['id' => $user->id]), fullname($user)) : '',
            $row->activitydate ? userdate($row->activitydate, $dateformat, core_date::get_server_timezone(), false) : '',
            $categories[$row->categoryid] ?? '',
            $row->hours > 0 ? format_float($row->hours, 2) : '',
            s((string) $row->title),
            $result,
        ];
    }
    if ($table->data) {
        echo html_writer::table($table);
        echo $OUTPUT->paging_bar($total, $page, $perpage, $PAGE->url);
    } else {
        echo html_writer::tag('p', get_string('import:norows', 'local_cpdlog'));
    }
}

// Recent runs.
$runs = $DB->get_records(auditor::RUN_TABLE, null, 'timecreated DESC, id DESC', '*', 0, 10);
if ($runs) {
    echo $OUTPUT->heading(get_string('import:recent', 'local_cpdlog'), 3);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable local-cpdlog-importruns';
    $table->head = [
        get_string('date'),
        get_string('import:source', 'local_cpdlog'),
        get_string('import:memberid', 'local_cpdlog'),
        get_string('import:outcome:new', 'local_cpdlog'),
        get_string('import:outcome:conflict', 'local_cpdlog'),
        get_string('import:outcome:unmatched', 'local_cpdlog'),
        get_string('status'),
    ];
    foreach ($runs as $item) {
        $counts = (array) json_decode((string) $item->counts);
        $when = userdate($item->timecreated, get_string('strftimedatetimefull', 'local_cpdlog'));
        $table->data[] = [
            html_writer::link(new moodle_url($url, ['runid' => $item->id]), $when),
            get_string('import:source:' . $item->source, 'local_cpdlog'),
            $item->memberid === null ? get_string('import:everyone', 'local_cpdlog') : s($item->memberid),
            (int) ($counts['new'] ?? 0),
            (int) ($counts['conflict'] ?? 0),
            (int) ($counts['unmatched'] ?? 0),
            get_string('import:status:' . $item->status, 'local_cpdlog'),
        ];
    }
    echo html_writer::table($table);
}
echo html_writer::link(new moodle_url('/local/cpdlog/admin/importconflicts.php'), get_string('import:conflicts', 'local_cpdlog'));
echo $OUTPUT->footer();
