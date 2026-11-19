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
 * Upcoming CPD from Moodle: courses that award CPD, and calendar events in them.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local\upcoming;

use local_cpdlog\local\course_cpd;

/**
 * Upcoming CPD from Moodle.
 *
 * Covers visible courses in visible categories whose "CPD hours" are set: those starting in the
 * window, and course events in them and category events in their categories that start in the
 * window. The list is the same for everyone, so it is cached for a short while; each member then
 * sees which items are theirs, from their active enrolments.
 */
class moodle_source implements source
{
    /** @var int How long the list is cached, in seconds. */
    const CACHE_TTL = 900;

    /**
     * Returns the upcoming CPD for a member, soonest first.
     *
     * @param int $userid The member.
     * @param int $from The start of the window.
     * @param int $to The end of the window.
     * @return \stdClass[]
     */
    public function get_items(int $userid, int $from, int $to): array {
        // One list is kept, for the latest window asked for.
        $cache = \cache::make('local_cpdlog', 'upcoming');
        $cached = $cache->get('items');
        if ($cached === false || $cached->expires < time() || $cached->from !== $from || $cached->to !== $to) {
            $cached = (object) [
                'from' => $from,
                'to' => $to,
                'expires' => time() + self::CACHE_TTL,
                'items' => self::load($from, $to),
            ];
            $cache->set('items', $cached);
        }

        $enrolled = array_map('intval', array_keys(enrol_get_all_users_courses($userid, true, 'id')));
        $items = [];
        foreach ($cached->items as $item) {
            $item = clone $item;
            // A category event is the member's when they take any CPD course in that category.
            $item->mine = (bool) array_intersect($item->courseids, $enrolled);
            unset($item->courseids);
            $items[] = $item;
        }
        return $items;
    }

    /**
     * Builds the list for everyone, soonest first.
     *
     * @param int $from The start of the window.
     * @param int $to The end of the window.
     * @return \stdClass[] Items as in source, with courseids (the courses the item belongs to) instead of mine.
     */
    protected static function load(int $from, int $to): array {
        global $DB;
        $field = course_cpd::get_field(course_cpd::FIELD_HOURS);
        if (!$field) {
            return [];
        }
        // Courses hidden themselves, or in a hidden category, are left out.
        $sql = 'SELECT c.id, c.fullname, c.startdate, c.category
                  FROM {course} c
                  JOIN {course_categories} cc ON cc.id = c.category AND cc.visible = 1
                  JOIN {customfield_data} d ON d.instanceid = c.id AND d.fieldid = :fieldid AND d.decvalue > 0
                 WHERE c.id <> :siteid AND c.visible = 1';
        $courses = $DB->get_records_sql($sql, ['fieldid' => $field->get('id'), 'siteid' => SITEID]);
        if (!$courses) {
            return [];
        }

        $items = [];
        $bycategory = [];
        foreach ($courses as $course) {
            $context = \context_course::instance($course->id);
            $course->name = format_string($course->fullname, true, ['context' => $context, 'escape' => false]);
            $course->cpd = self::course_cpd((int) $course->id);
            $bycategory[$course->category][] = (int) $course->id;
            if ($course->startdate >= $from && $course->startdate < $to) {
                $items[] = self::item('course', $course->name, $course, (int) $course->startdate, true, [(int) $course->id]);
            }
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal(array_keys($courses), SQL_PARAMS_NAMED, 'course');
        [$catsql, $catparams] = $DB->get_in_or_equal(array_keys($bycategory), SQL_PARAMS_NAMED, 'cat');
        $sql = "SELECT id, name, eventtype, courseid, categoryid, timestart
                  FROM {event}
                 WHERE visible = 1 AND timestart >= :from AND timestart < :to
                   AND ((eventtype = 'course' AND courseid {$coursesql})
                        OR (eventtype = 'category' AND categoryid {$catsql}))
              ORDER BY timestart, id";
        $params = ['from' => $from, 'to' => $to] + $courseparams + $catparams;
        foreach ($DB->get_records_sql($sql, $params) as $event) {
            if ($event->eventtype === 'course') {
                $course = $courses[$event->courseid];
                $context = \context_course::instance($course->id);
                $title = format_string($event->name, true, ['context' => $context, 'escape' => false]);
                $items[] = self::item('event', $title, $course, (int) $event->timestart, false, [(int) $course->id]);
            } else {
                $context = \context_coursecat::instance($event->categoryid);
                $title = format_string($event->name, true, ['context' => $context, 'escape' => false]);
                $item = self::item('event', $title, null, (int) $event->timestart, false, $bycategory[$event->categoryid]);
                $item->url = (new \moodle_url('/calendar/view.php', [
                    'view' => 'day',
                    'time' => $event->timestart,
                    'category' => $event->categoryid,
                ]))->out(false);
                $items[] = $item;
            }
        }

        usort($items, fn($a, $b) => [$a->timestart, $a->title] <=> [$b->timestart, $b->title]);
        return $items;
    }

    /**
     * Returns one item.
     *
     * @param string $type course or event.
     * @param string $title Plain-text title.
     * @param \stdClass|null $course The course it belongs to, with name and cpd, or null.
     * @param int $timestart When it starts.
     * @param bool $allday Whether only the date matters.
     * @param int[] $courseids The courses it belongs to.
     * @return \stdClass
     */
    protected static function item(
        string $type,
        string $title,
        ?\stdClass $course,
        int $timestart,
        bool $allday,
        array $courseids
    ): \stdClass {
        $cpd = $course ? $course->cpd : null;
        return (object) [
            'type' => $type,
            'title' => $title,
            'coursename' => $course ? $course->name : '',
            'timestart' => $timestart,
            'allday' => $allday,
            'hours' => $cpd ? format_float($cpd->hours, 2) : '',
            'category' => $cpd ? format_string($cpd->category->get('name'), true, ['escape' => false]) : '',
            'url' => $course ? (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false) : '',
            'courseids' => $courseids,
        ];
    }

    /**
     * Returns a course's CPD, or null if it cannot be worked out (for example, no category is enabled).
     *
     * @param int $courseid The course.
     * @return \stdClass|null
     */
    protected static function course_cpd(int $courseid): ?\stdClass {
        try {
            return course_cpd::get_course_cpd($courseid);
        } catch (\moodle_exception $e) {
            unset($e);
            return null;
        }
    }
}
