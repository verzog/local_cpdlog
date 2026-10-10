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
 * Lists the courses that award CPD when members complete them.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_cpdlog\local\course_cpd;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Checks login and local/cpdlog:viewall at system context.
admin_externalpage_setup('local_cpdlog_courses');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('cpdcourses', 'local_cpdlog'));
echo html_writer::tag('p', get_string('cpdcourses_desc', 'local_cpdlog'));
if (!course_cpd::is_enabled()) {
    echo $OUTPUT->notification(get_string('coursecompleted:off', 'local_cpdlog'), 'warning');
}

$field = course_cpd::get_field(course_cpd::FIELD_HOURS);
if (!$field) {
    echo $OUTPUT->notification(get_string('coursecompleted:nofield', 'local_cpdlog'), 'warning');
    echo $OUTPUT->footer();
    die();
}

// Released entries are counted once for all courses, rather than per course.
$sql = 'SELECT c.id, c.fullname, c.shortname, c.visible, COALESCE(l.awarded, 0) AS awarded
          FROM {course} c
          JOIN {customfield_data} d ON d.instanceid = c.id AND d.fieldid = :fieldid AND d.decvalue > 0
     LEFT JOIN (SELECT courseid, COUNT(1) AS awarded
                  FROM {' . course_cpd::TABLE . '}
                 WHERE status = :released
              GROUP BY courseid) l ON l.courseid = c.id
      ORDER BY c.fullname, c.id';
$courses = $DB->get_records_sql($sql, ['fieldid' => $field->get('id'), 'released' => course_cpd::STATUS_RELEASED]);
$waiting = course_cpd::count_waiting();
$canrelease = has_capability('local/cpdlog:approve', context_system::instance());

$table = new html_table();
$table->head = [
    get_string('course'),
    get_string('hours', 'local_cpdlog'),
    get_string('category'),
    get_string('cpdcourses:awarded', 'local_cpdlog'),
    get_string('release:waiting', 'local_cpdlog'),
    get_string('actions'),
];
$table->attributes['class'] = 'generaltable local-cpdlog-courses';
foreach ($courses as $course) {
    $cpd = course_cpd::get_course_cpd((int) $course->id);
    $name = format_string($course->fullname, true, ['context' => context_course::instance($course->id)]);
    if (!$course->visible) {
        $name .= ' ' . html_writer::span(get_string('hiddenfromstudents'), 'badge bg-secondary');
    }
    // Approvers release completions from the course's checklist.
    $actions = [];
    if ($canrelease) {
        $actions[] = html_writer::link(
            new moodle_url('/local/cpdlog/admin/release.php', ['courseid' => $course->id]),
            get_string('release:link', 'local_cpdlog')
        );
    }
    $actions[] = html_writer::link(new moodle_url('/course/edit.php', ['id' => $course->id]), get_string('editcoursesettings'));
    $table->data[] = [
        html_writer::link(new moodle_url('/course/view.php', ['id' => $course->id]), $name),
        format_float($cpd->hours, 2),
        format_string($cpd->category->get('name')),
        $course->awarded,
        $waiting[$course->id] ?? 0,
        implode(html_writer::empty_tag('br'), $actions),
    ];
}

if ($table->data) {
    echo html_writer::table($table);
} else {
    echo html_writer::tag('p', get_string('cpdcourses:none', 'local_cpdlog'));
}
echo $OUTPUT->footer();
