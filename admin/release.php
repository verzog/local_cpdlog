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
 * Release checklist: approvers release a course's completions to members' CPD logbooks, or exclude them.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\checkbox_toggleall;
use local_cpdlog\local\course_cpd;

require(__DIR__ . '/../../../config.php');

require_login(null, false);
$context = context_system::instance();
require_capability('local/cpdlog:approve', $context);

$courseid = required_param('courseid', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], 'id, fullname', MUST_EXIST);
$url = new moodle_url('/local/cpdlog/admin/release.php', ['courseid' => $courseid]);
$coursename = format_string($course->fullname, true, ['context' => context_course::instance($courseid)]);
$heading = get_string('release:heading', 'local_cpdlog', $coursename);

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title($heading);
$PAGE->set_heading(get_string('pluginname', 'local_cpdlog'));
$PAGE->navbar->add(get_string('cpdcourses', 'local_cpdlog'), new moodle_url('/local/cpdlog/admin/courses.php'));
$PAGE->navbar->add($coursename);

// Release or exclude the ticked members.
$action = optional_param('action', '', PARAM_ALPHA);
if ($action !== '') {
    require_sesskey();
    $userids = optional_param_array('userids', [], PARAM_INT);
    if (!in_array($action, ['release', 'exclude'], true)) {
        throw new moodle_exception('invalidparameter', 'debug');
    }
    $done = 0;
    $skipped = 0;
    foreach (array_unique($userids) as $userid) {
        if ($action === 'exclude') {
            $done += (int) course_cpd::exclude((int) $userid, $courseid, (int) $USER->id);
            continue;
        }
        $outcome = course_cpd::release((int) $userid, $courseid, (int) $USER->id);
        if ($outcome === 'released') {
            $done++;
        } else if ($outcome === 'skipped') {
            $skipped++;
        }
    }
    $message = get_string('release:' . $action . 'done', 'local_cpdlog', $done);
    if ($skipped) {
        $message .= ' ' . get_string('release:skipped', 'local_cpdlog', $skipped);
    }
    $type = $skipped ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS;
    redirect($url, $message, null, $type);
}

$cpd = course_cpd::get_course_cpd($courseid);
$dateformat = get_string('strftimedatefull', 'local_cpdlog');
$timezone = core_date::get_server_timezone();

echo $OUTPUT->header();
echo $OUTPUT->heading($heading);
if (!course_cpd::is_enabled()) {
    echo $OUTPUT->notification(get_string('coursecompleted:off', 'local_cpdlog'), 'warning');
    echo $OUTPUT->footer();
    die();
}
if (!$cpd) {
    echo $OUTPUT->notification(get_string('release:nocpd', 'local_cpdlog'), 'warning');
    echo $OUTPUT->footer();
    die();
}
echo html_writer::tag('p', get_string('release:intro', 'local_cpdlog', (object) [
    'hours' => format_float($cpd->hours, 2),
    'category' => format_string($cpd->category->get('name')),
]));

/**
 * Prints a checklist form of members with a select-all box and action buttons.
 *
 * @param string $group The toggle group, also used for the form id.
 * @param array $headings Column headings after the tick box.
 * @param array $rows Rows: [userid or null when it cannot be ticked, label for the tick box, cells].
 * @param array $buttons Action => button label.
 * @param moodle_url $url The form action.
 */
function local_cpdlog_release_checklist(string $group, array $headings, array $rows, array $buttons, moodle_url $url): void {
    global $OUTPUT;
    $table = new html_table();
    $table->attributes['class'] = 'generaltable local-cpdlog-' . $group;
    $table->head = array_merge([$OUTPUT->render(new checkbox_toggleall($group, true, [
        'id' => $group . '-all',
        'name' => $group . '-all',
        'label' => get_string('selectall'),
        'labelclasses' => 'accesshide',
        'checked' => false,
    ]))], $headings);
    foreach ($rows as [$userid, $label, $cells]) {
        $box = $userid === null ? '' : $OUTPUT->render(new checkbox_toggleall($group, false, [
            'id' => $group . '-' . $userid,
            'name' => 'userids[]',
            'value' => $userid,
            'label' => $label,
            'labelclasses' => 'accesshide',
        ]));
        $table->data[] = array_merge([$box], $cells);
    }
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out_omit_querystring(), 'id' => $group]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $url->get_param('courseid')]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::table($table);
    foreach ($buttons as $action => $label) {
        echo html_writer::tag('button', $label, [
            'type' => 'submit',
            'name' => 'action',
            'value' => $action,
            'class' => $action === 'release' ? 'btn btn-primary me-2' : 'btn btn-secondary me-2',
        ]);
    }
    echo html_writer::end_tag('form');
}

// Members waiting to be released.
echo $OUTPUT->heading(get_string('release:waiting', 'local_cpdlog'), 3);
$rows = [];
foreach (course_cpd::get_waiting($courseid) as $row) {
    $name = fullname($row);
    $note = $row->releasable ? '' : html_writer::div(get_string('release:noperiod', 'local_cpdlog'), 'small text-muted');
    $rows[] = [
        $row->releasable ? (int) $row->userid : null,
        $name,
        [s($name) . $note, s($row->email), userdate($row->timecompleted, $dateformat, $timezone, false)],
    ];
}
if ($rows) {
    local_cpdlog_release_checklist(
        'waiting',
        [get_string('fullname'), get_string('email'), get_string('release:completed', 'local_cpdlog')],
        $rows,
        [
            'release' => get_string('release:release', 'local_cpdlog'),
            'exclude' => get_string('release:exclude', 'local_cpdlog'),
        ],
        $url
    );
} else {
    echo html_writer::tag('p', get_string('release:nonewaiting', 'local_cpdlog'));
}

// Members excluded earlier, who can still be released.
$excluded = course_cpd::get_excluded($courseid);
if ($excluded) {
    echo $OUTPUT->heading(get_string('release:excluded', 'local_cpdlog'), 3);
    $rows = [];
    foreach ($excluded as $row) {
        $name = fullname($row);
        $staff = fullname(username_load_fields_from_object(new stdClass(), $row, 'staff'));
        $rows[] = [
            (int) $row->userid,
            $name,
            [
                s($name),
                s($row->email),
                userdate($row->timecompleted, $dateformat, $timezone, false),
                s($staff),
                userdate($row->timemodified, $dateformat, $timezone, false),
            ],
        ];
    }
    local_cpdlog_release_checklist(
        'excluded',
        [
            get_string('fullname'),
            get_string('email'),
            get_string('release:completed', 'local_cpdlog'),
            get_string('release:excludedby', 'local_cpdlog'),
            get_string('release:excludedon', 'local_cpdlog'),
        ],
        $rows,
        ['release' => get_string('release:release', 'local_cpdlog')],
        $url
    );
}
echo $OUTPUT->footer();
