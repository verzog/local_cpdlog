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
 * Lists members with cohort target conflicts in a period and records staff choices.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

use local_cpdlog\local\target_resolver;
use local_cpdlog\persistent\period;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$periodid = required_param('periodid', PARAM_INT);
$url = new moodle_url('/local/cpdlog/admin/conflicts.php', ['periodid' => $periodid]);

// Checks login and local/cpdlog:manageperiods at system context.
admin_externalpage_setup('local_cpdlog_periods', '', null, $url);

$period = new period($periodid);
$editable = !$period->is_closed();

if (optional_param('savechoice', false, PARAM_BOOL)) {
    require_sesskey();
    if (!$editable) {
        throw new moodle_exception('error:periodclosed', 'local_cpdlog', $url);
    }
    $userid = required_param('userid', PARAM_INT);
    $choice = required_param('choice', PARAM_ALPHANUM);
    if ($choice === '') {
        redirect($url, get_string('error:choosecohort', 'local_cpdlog'), null, \core\output\notification::NOTIFY_ERROR);
    }
    // The resolver checks that the member has a conflict and the cohort is one of theirs.
    target_resolver::set_choice($userid, $periodid, $choice === 'none' ? null : (int) $choice, (int) $USER->id);
    redirect($url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$conflicts = target_resolver::get_conflicts($periodid);
$cohortnames = [];
foreach ($DB->get_records('cohort', null, '', 'id, name, contextid') as $cohort) {
    $cohortnames[$cohort->id] = format_string($cohort->name, true, ['context' => context::instance_by_id($cohort->contextid)]);
}
$users = [];
if ($conflicts) {
    $userfields = \core_user\fields::for_name()->get_sql('u', true);
    [$insql, $inparams] = $DB->get_in_or_equal(array_keys($conflicts), SQL_PARAMS_NAMED);
    $sql = "SELECT u.id {$userfields->selects} FROM {user} u WHERE u.id $insql";
    $users = $DB->get_records_sql($sql, $inparams + $userfields->params);
}

// Unresolved conflicts first, then by name.
uasort($conflicts, fn($a, $b) => [$a->resolved, fullname($users[$a->userid])] <=> [$b->resolved, fullname($users[$b->userid])]);

$table = new html_table();
$table->head = [
    get_string('member', 'local_cpdlog'),
    get_string('conflictcohorts', 'local_cpdlog'),
    get_string('currentchoice', 'local_cpdlog'),
    get_string('status'),
];
if ($editable) {
    $table->head[] = get_string('choose');
}
$table->attributes['class'] = 'generaltable local-cpdlog-conflicts';

foreach ($conflicts as $conflict) {
    $fullname = fullname($users[$conflict->userid]);
    if ($conflict->resolved) {
        $current = $conflict->choice->cohortid === null
            ? get_string('choicenone', 'local_cpdlog')
            : $cohortnames[$conflict->choice->cohortid];
    } else {
        $current = $conflict->choice ? get_string('choicestale', 'local_cpdlog') : get_string('choicenotmade', 'local_cpdlog');
    }
    $row = [
        s($fullname),
        implode(', ', array_map(fn($cohortid) => $cohortnames[$cohortid], $conflict->cohortids)),
        $current,
        $conflict->resolved ? get_string('resolved', 'local_cpdlog') : get_string('unresolved', 'local_cpdlog'),
    ];

    if ($editable) {
        $options = ['' => get_string('choosedots'), 'none' => get_string('choicenone', 'local_cpdlog')];
        foreach ($conflict->cohortids as $cohortid) {
            $options[$cohortid] = $cohortnames[$cohortid];
        }
        $selected = '';
        if ($conflict->resolved) {
            $selected = $conflict->choice->cohortid === null ? 'none' : (string) $conflict->choice->cohortid;
        }
        $selectid = 'local-cpdlog-choice-' . $conflict->userid;
        $form = html_writer::label(get_string('choicefor', 'local_cpdlog', $fullname), $selectid, false, ['class' => 'accesshide'])
            . html_writer::select($options, 'choice', $selected, false, ['id' => $selectid, 'class' => 'me-2'])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => $conflict->userid])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
            . html_writer::empty_tag('input', [
                'type' => 'submit',
                'name' => 'savechoice',
                'value' => get_string('save'),
                'class' => 'btn btn-secondary',
            ]);
        $row[] = html_writer::tag('form', $form, ['method' => 'post', 'action' => $url->out(false), 'class' => 'd-flex']);
    }
    $table->data[] = $row;
}

$heading = get_string('conflictsfor', 'local_cpdlog', format_string($period->get('name')));
$PAGE->navbar->add($heading);
echo $OUTPUT->header();
echo $OUTPUT->heading($heading);
echo html_writer::tag('p', get_string('conflicts_desc', 'local_cpdlog'));
if (!$editable) {
    echo $OUTPUT->notification(get_string('periodclosedconflicts', 'local_cpdlog'), 'info');
}
if ($conflicts) {
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('noconflicts', 'local_cpdlog'), 'info');
}
echo $OUTPUT->single_button(
    new moodle_url('/local/cpdlog/admin/targets.php', ['periodid' => $periodid]),
    get_string('backtotargets', 'local_cpdlog'),
    'get'
);
echo $OUTPUT->footer();
