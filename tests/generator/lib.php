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
 * Test data generator for the CPD logbook plugin.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_cpdlog\local\dates;
use local_cpdlog\persistent\category;
use local_cpdlog\persistent\entry;
use local_cpdlog\persistent\period;
use local_cpdlog\persistent\target;

/**
 * Test data generator for the CPD logbook plugin.
 */
class local_cpdlog_generator extends component_generator_base
{
    /**
     * Creates a reporting period.
     *
     * @param array $record name, and either firstday and lastday as DD/MM/YYYY in the site timezone,
     *                      or startdate and enddate timestamps; status is optional.
     * @return period
     */
    public function create_period(array $record): period {
        if (isset($record['firstday'])) {
            $record['startdate'] = self::parse_day($record['firstday']);
            $record['enddate'] = dates::next_day_start(self::parse_day($record['lastday']));
            unset($record['firstday'], $record['lastday']);
        }
        return (new period(0, (object) $record))->create();
    }

    /**
     * Creates a target.
     *
     * @param array $record name and requiredhours; periodid or period (name); optionally cohortid or
     *                      cohort (idnumber), and categories as comma-separated short names.
     * @return target
     */
    public function create_target(array $record): target {
        global $DB;
        if (isset($record['period'])) {
            $record['periodid'] = period::get_record(['name' => $record['period']], MUST_EXIST)->get('id');
            unset($record['period']);
        }
        if (!empty($record['cohort'])) {
            $record['cohortid'] = $DB->get_field('cohort', 'id', ['idnumber' => $record['cohort']], MUST_EXIST);
        }
        $categoryids = [];
        foreach (array_filter(array_map('trim', explode(',', $record['categories'] ?? ''))) as $shortname) {
            $categoryids[] = category::get_record(['shortname' => $shortname], MUST_EXIST)->get('id');
        }
        unset($record['cohort'], $record['categories']);

        $target = (new target(0, (object) $record))->create();
        $target->set_categoryids($categoryids);
        return $target;
    }

    /**
     * Creates an entry directly, in any state, without the member-facing rules.
     *
     * @param array $record userid or user (username); periodid or period (name); optionally
     *                      categoryid or category (short name, default EA), courseid or course (short
     *                      name), activitydate or day (DD/MM/YYYY, default the period's first day),
     *                      hours (default 1), evidence (comma-separated file names to attach),
     *                      status and any other entry field.
     * @return entry
     */
    public function create_entry(array $record): entry {
        global $DB;
        if (isset($record['user'])) {
            $record['userid'] = $DB->get_field('user', 'id', ['username' => $record['user']], MUST_EXIST);
        }
        if (isset($record['period'])) {
            $record['periodid'] = period::get_record(['name' => $record['period']], MUST_EXIST)->get('id');
        }
        $period = new period($record['periodid']);
        $record['categoryid'] = $record['categoryid']
            ?? category::get_record(['shortname' => $record['category'] ?? 'EA'], MUST_EXIST)->get('id');
        if (isset($record['course'])) {
            $course = $DB->get_record('course', ['shortname' => $record['course']], 'id, fullname', MUST_EXIST);
            $record['courseid'] = $course->id;
            $record['coursename'] = $course->fullname;
        }
        if (isset($record['day'])) {
            $record['activitydate'] = self::parse_day($record['day']);
        }
        $record['activitydate'] = $record['activitydate'] ?? $period->get('startdate');
        $record['hours'] = $record['hours'] ?? 1;
        $evidence = array_filter(array_map('trim', explode(',', $record['evidence'] ?? '')));
        unset($record['user'], $record['period'], $record['category'], $record['course'], $record['day'], $record['evidence']);

        $entry = (new entry(0, (object) $record))->create();
        foreach ($evidence as $filename) {
            get_file_storage()->create_file_from_string([
                'contextid' => context_system::instance()->id,
                'component' => 'local_cpdlog',
                'filearea' => \local_cpdlog\local\entry_manager::EVIDENCE_AREA,
                'itemid' => $entry->get('id'),
                'filepath' => '/',
                'filename' => $filename,
            ], 'Evidence: ' . $filename);
        }
        return $entry;
    }

    /**
     * Deletes a Moodle account the way an administrator would, leaving its CPD data in place.
     *
     * @param array $record user (username).
     * @return \stdClass The deleted account, with its scrambled username and email address.
     */
    public function create_deleted_account(array $record): \stdClass {
        global $DB;
        $user = $DB->get_record('user', ['username' => $record['user'], 'deleted' => 0], '*', MUST_EXIST);
        delete_user($user);
        return $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
    }

    /**
     * Records a member's completion of a course, as course completion tracking does.
     *
     * @param array $record user (username), course (short name) and day (DD/MM/YYYY, default today).
     * @return int The completion id.
     */
    public function create_course_completion(array $record): int {
        global $DB;
        $userid = $DB->get_field('user', 'id', ['username' => $record['user']], MUST_EXIST);
        $courseid = $DB->get_field('course', 'id', ['shortname' => $record['course']], MUST_EXIST);
        return (int) $DB->insert_record('course_completions', (object) [
            'userid' => $userid,
            'course' => $courseid,
            'timeenrolled' => 0,
            'timestarted' => 0,
            'timecompleted' => isset($record['day']) ? self::parse_day($record['day']) + 10 * HOURSECS : time(),
        ]);
    }

    /**
     * Records a CPD award in the image blog's own tables, as local_imageblog does on a revealed case.
     *
     * @param array $record user (username), case (post title, created if new), reason (participation,
     *                      bestanswer or view; default participation), hours (default 1) and day
     *                      (DD/MM/YYYY, default today).
     * @return int The award id.
     */
    public function create_imageblog_award(array $record): int {
        global $DB;
        $userid = $DB->get_field('user', 'id', ['username' => $record['user']], MUST_EXIST);
        $title = $record['case'] ?? 'Clinical case';
        $postid = $DB->get_field(\local_cpdlog\local\imageblog_sync::POST_TABLE, 'id', ['title' => $title]);
        if (!$postid) {
            $postid = $DB->insert_record(\local_cpdlog\local\imageblog_sync::POST_TABLE, (object) [
                'authorid' => get_admin()->id,
                'title' => $title,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }
        return (int) $DB->insert_record(\local_cpdlog\local\imageblog_sync::AWARD_TABLE, (object) [
            'postid' => $postid,
            'userid' => $userid,
            'hours' => $record['hours'] ?? 1,
            'reason' => $record['reason'] ?? 'participation',
            'timeawarded' => isset($record['day']) ? self::parse_day($record['day']) + 10 * HOURSECS : time(),
        ]);
    }

    /**
     * Parses a DD/MM/YYYY date as the start of that day in the site timezone.
     *
     * @param string $day The date.
     * @return int
     */
    private static function parse_day(string $day): int {
        $date = DateTimeImmutable::createFromFormat('!d/m/Y', $day, core_date::get_server_timezone_object());
        if (!$date) {
            throw new coding_exception('Dates must be DD/MM/YYYY: ' . $day);
        }
        return $date->getTimestamp();
    }
}
