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
 * Rules for members logging, editing and submitting CPD entries.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\local;

use local_cpdlog\event\entry_created;
use local_cpdlog\event\entry_deleted;
use local_cpdlog\event\entry_submitted;
use local_cpdlog\event\entry_updated;
use local_cpdlog\persistent\category;
use local_cpdlog\persistent\entry;
use local_cpdlog\persistent\period;

/**
 * Rules for members logging, editing and submitting CPD entries.
 *
 * Every write goes through here, so the rules hold whichever page or service calls them. Callers
 * must still check login, capability and session key; this class checks ownership and state.
 */
final class entry_manager
{
    /**
     * Returns the courses a member can log CPD against: current or past enrolments, and completions.
     *
     * @param int $userid The member.
     * @return string[] Course names keyed by course id, sorted by name.
     */
    public static function get_course_options(int $userid): array {
        global $DB;
        $courses = [];
        foreach (enrol_get_all_users_courses($userid, false, ['fullname']) as $course) {
            $courses[$course->id] = $course->fullname;
        }
        // Members fully unenrolled after finishing a course keep their completion record.
        $sql = 'SELECT c.id, c.fullname
                  FROM {course_completions} cc
                  JOIN {course} c ON c.id = cc.course
                 WHERE cc.userid = :userid AND cc.timecompleted IS NOT NULL';
        foreach ($DB->get_records_sql($sql, ['userid' => $userid]) as $course) {
            $courses[$course->id] = $course->fullname;
        }
        unset($courses[SITEID]);

        $options = [];
        foreach ($courses as $courseid => $fullname) {
            $options[$courseid] = format_string($fullname, true, ['context' => \context_course::instance($courseid)]);
        }
        \core_collator::asort($options);
        return $options;
    }

    /**
     * Whether a member is or was enrolled in a course, or has completed it.
     *
     * @param int $userid The member.
     * @param int $courseid The course.
     * @return bool
     */
    public static function can_log_course(int $userid, int $courseid): bool {
        global $DB;
        if ($courseid == SITEID || !$DB->record_exists('course', ['id' => $courseid])) {
            return false;
        }
        // Passing false for onlyactive accepts suspended and expired enrolments.
        if (is_enrolled(\context_course::instance($courseid), $userid, '', false)) {
            return true;
        }
        $select = 'userid = :userid AND course = :course AND timecompleted IS NOT NULL';
        return $DB->record_exists_select('course_completions', $select, ['userid' => $userid, 'course' => $courseid]);
    }

    /**
     * Returns the period containing a date, whether open or closed.
     *
     * @param int $activitydate A moment in the day of the activity.
     * @return period|null
     */
    public static function find_period(int $activitydate): ?period {
        $periods = period::get_records_select('startdate <= :date1 AND enddate > :date2', [
            'date1' => $activitydate,
            'date2' => $activitydate,
        ]);
        return $periods ? reset($periods) : null;
    }

    /**
     * Returns the largest number of hours one entry may claim.
     *
     * @return float
     */
    public static function get_max_hours(): float {
        $max = (float) get_config('local_cpdlog', 'maxhoursperentry');
        return $max > 0 ? min($max, entry::MAX_HOURS) : entry::MAX_HOURS;
    }

    /**
     * Checks a member's entry details against the logging rules.
     *
     * @param int $userid The member.
     * @param \stdClass $data categoryid, courseid, activitydate and hours.
     * @param entry|null $existing The entry being edited, if any; its current category and course stay allowed.
     * @return string[] Error messages keyed by field name; empty when the details are valid.
     */
    public static function validate(int $userid, \stdClass $data, ?entry $existing = null): array {
        $errors = [];

        $categoryid = (int) ($data->categoryid ?? 0);
        $category = $categoryid ? category::get_record(['id' => $categoryid]) : false;
        $keptcategory = $existing && (int) $existing->get('categoryid') === $categoryid;
        if (!$category || (!$category->get('enabled') && !$keptcategory)) {
            $errors['categoryid'] = get_string('error:entrycategory', 'local_cpdlog');
        }

        $courseid = (int) ($data->courseid ?? 0);
        $keptcourse = $existing && (int) $existing->get('courseid') === $courseid;
        if (!$courseid || (!$keptcourse && !self::can_log_course($userid, $courseid))) {
            $errors['courseid'] = get_string('error:entrycourse', 'local_cpdlog');
        }

        $period = self::find_period((int) ($data->activitydate ?? 0));
        if (!$period) {
            $errors['activitydate'] = get_string('error:entrynoperiod', 'local_cpdlog');
        } else if ($period->is_closed()) {
            $errors['activitydate'] = get_string('error:entryperiodclosed', 'local_cpdlog', format_string($period->get('name')));
        }

        $hours = (float) ($data->hours ?? 0);
        $max = self::get_max_hours();
        if ($hours < 0.01 || abs(round($hours, 2) - $hours) > 1e-9) {
            $errors['hours'] = get_string('error:entryhours', 'local_cpdlog');
        } else if ($hours > $max) {
            $errors['hours'] = get_string('error:entryhoursmax', 'local_cpdlog', format_float($max, 2));
        }

        return $errors;
    }

    /**
     * Whether a member may change or delete an entry: their own, created in Moodle, not yet
     * submitted or approved, and in an open period.
     *
     * @param entry $entry The entry.
     * @param int $userid The member.
     * @return bool
     */
    public static function can_edit(entry $entry, int $userid): bool {
        return (int) $entry->get('userid') === $userid
            && $entry->is_moodle_owned()
            && in_array($entry->get('status'), [entry::STATUS_DRAFT, entry::STATUS_REJECTED], true)
            && !(new period($entry->get('periodid')))->is_closed();
    }

    /**
     * Creates a draft entry, or saves changes to one. Saving a rejected entry returns it to draft.
     *
     * @param int $userid The member.
     * @param \stdClass $data categoryid, courseid, activitydate, hours, description and descriptionformat.
     * @param entry|null $entry The entry to change, or null to create one.
     * @return entry The saved entry.
     * @throws \moodle_exception If the member may not change the entry or the details break a rule.
     */
    public static function save_draft(int $userid, \stdClass $data, ?entry $entry = null): entry {
        global $DB;
        if ($entry && !self::can_edit($entry, $userid)) {
            throw new \moodle_exception('error:entrylocked', 'local_cpdlog');
        }
        if ($errors = self::validate($userid, $data, $entry)) {
            throw new \moodle_exception('error:entryinvalid', 'local_cpdlog', '', implode(' ', $errors));
        }

        $activitydate = (int) $data->activitydate;
        $courseid = (int) $data->courseid;
        $record = (object) [
            'userid' => $userid,
            'categoryid' => (int) $data->categoryid,
            'periodid' => self::find_period($activitydate)->get('id'),
            'courseid' => $courseid,
            'coursename' => $DB->get_field('course', 'fullname', ['id' => $courseid]) ?: null,
            'hours' => round((float) $data->hours, 2),
            'activitydate' => $activitydate,
            'description' => $data->description ?? '',
            'descriptionformat' => (int) ($data->descriptionformat ?? FORMAT_HTML),
            'status' => entry::STATUS_DRAFT,
            'source' => entry::SOURCE_MOODLE,
        ];
        // A course deleted since the entry was logged keeps its snapshot name.
        if ($entry && $record->coursename === null) {
            $record->coursename = $entry->get('coursename');
        }

        $transaction = $DB->start_delegated_transaction();
        if ($entry) {
            $entry->from_record($record);
            $entry->update();
            $event = entry_updated::create_from_entry($entry);
        } else {
            $entry = (new entry(0, $record))->create();
            $event = entry_created::create_from_entry($entry);
        }
        $event->trigger();
        $transaction->allow_commit();
        return $entry;
    }

    /**
     * Submits a draft for staff review, rechecking the rules in case anything changed since it was saved.
     *
     * @param entry $entry The draft.
     * @param int $userid The member.
     * @throws \moodle_exception If the entry is not the member's draft or now breaks a rule.
     */
    public static function submit(entry $entry, int $userid): void {
        global $DB;
        if (!self::can_edit($entry, $userid) || $entry->get('status') !== entry::STATUS_DRAFT) {
            throw new \moodle_exception('error:entrylocked', 'local_cpdlog');
        }
        // The current category and course stay allowed, as when editing.
        $errors = self::validate($userid, $entry->to_record(), $entry);
        if ($errors) {
            throw new \moodle_exception('error:entryinvalid', 'local_cpdlog', '', implode(' ', $errors));
        }

        $transaction = $DB->start_delegated_transaction();
        $entry->set('status', entry::STATUS_SUBMITTED);
        $entry->set('timesubmitted', time());
        $entry->update();
        entry_submitted::create_from_entry($entry)->trigger();
        $transaction->allow_commit();
    }

    /**
     * Deletes a member's draft. Submitted, rejected and approved entries are kept for the record.
     *
     * @param entry $entry The draft.
     * @param int $userid The member.
     * @throws \moodle_exception If the entry is not the member's draft.
     */
    public static function delete_draft(entry $entry, int $userid): void {
        global $DB;
        if (!self::can_edit($entry, $userid) || $entry->get('status') !== entry::STATUS_DRAFT) {
            throw new \moodle_exception('error:entrylocked', 'local_cpdlog');
        }
        $transaction = $DB->start_delegated_transaction();
        $event = entry_deleted::create_from_entry($entry);
        $entry->delete();
        $event->trigger();
        $transaction->allow_commit();
    }

    /**
     * Counts the member's other entries for the same course on the same day, ignoring reversed ones.
     *
     * @param entry $entry The entry.
     * @return int
     */
    public static function count_duplicates(entry $entry): int {
        $select = 'userid = :userid AND courseid = :courseid AND activitydate = :activitydate
                   AND id <> :id AND status <> :reversed';
        return entry::count_records_select($select, [
            'userid' => $entry->get('userid'),
            'courseid' => $entry->get('courseid'),
            'activitydate' => $entry->get('activitydate'),
            'id' => $entry->get('id'),
            'reversed' => entry::STATUS_REVERSED,
        ]);
    }
}
