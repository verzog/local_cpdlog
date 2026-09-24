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
 * Test data generator for the CPD logbook plugin.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
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
