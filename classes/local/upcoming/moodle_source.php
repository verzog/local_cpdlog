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
        foreach ($cached->items as $raw) {
            // Names are formatted here, not before caching, so each member gets them in their own language.
            $coursecontext = $raw->courseid ? \context_course::instance($raw->courseid, IGNORE_MISSING) : null;
            $items[] = (object) [
                'type' => $raw->type,
                'title' => self::plain($raw->title, \context::instance_by_id($raw->contextid)),
                'coursename' => $coursecontext ? self::plain($raw->coursename, $coursecontext) : '',
                'timestart' => $raw->timestart,
                'allday' => $raw->allday,
                'hours' => $raw->hours === null ? '' : format_float($raw->hours, 2),
                'category' => $raw->category === '' ? '' : self::plain($raw->category, \context_system::instance()),
                'url' => $raw->url,
                // A category event is the member's when they take any CPD course in that category.
                'mine' => (bool) array_intersect($raw->courseids, $enrolled),
            ];
        }
        return $items;
    }

    /**
     * Builds the list for everyone, soonest first.
     *
     * @param int $from The start of the window.
     * @param int $to The end of the window.
     * @return \stdClass[] Unformatted items: type, title and contextid (to format it in), courseid and
     *                     coursename (0 and '' for none), timestart, allday, hours (float or null),
     *                     category (unformatted name or ''), url and courseids (the courses it belongs to).
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
            $course->contextid = \context_course::instance($course->id)->id;
            $course->cpd = self::course_cpd((int) $course->id);
            $bycategory[$course->category][] = (int) $course->id;
            if ($course->startdate >= $from && $course->startdate < $to) {
                $items[] = self::item('course', $course->fullname, $course->contextid, $course, (int) $course->startdate, true);
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
                $items[] = self::item('event', $event->name, $course->contextid, $course, (int) $event->timestart, false);
            } else {
                $contextid = \context_coursecat::instance($event->categoryid)->id;
                $item = self::item('event', $event->name, $contextid, null, (int) $event->timestart, false);
                $item->courseids = $bycategory[$event->categoryid];
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
     * Returns one unformatted item.
     *
     * @param string $type course or event.
     * @param string $title The title as stored.
     * @param int $contextid The context to format the title in.
     * @param \stdClass|null $course The course it belongs to, with contextid and cpd, or null.
     * @param int $timestart When it starts.
     * @param bool $allday Whether only the date matters.
     * @return \stdClass
     */
    protected static function item(
        string $type,
        string $title,
        int $contextid,
        ?\stdClass $course,
        int $timestart,
        bool $allday
    ): \stdClass {
        $cpd = $course ? $course->cpd : null;
        return (object) [
            'type' => $type,
            'title' => $title,
            'contextid' => $contextid,
            'courseid' => $course ? (int) $course->id : 0,
            'coursename' => $course ? $course->fullname : '',
            'timestart' => $timestart,
            'allday' => $allday,
            'hours' => $cpd ? (float) $cpd->hours : null,
            'category' => $cpd ? (string) $cpd->category->get('name') : '',
            'url' => $course ? (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false) : '',
            'courseids' => $course ? [(int) $course->id] : [],
        ];
    }

    /**
     * Formats a name as plain text, with filters applied, for a template to escape.
     *
     * @param string $text The name as stored.
     * @param \context $context Its context.
     * @return string
     */
    protected static function plain(string $text, \context $context): string {
        $text = format_string($text, true, ['context' => $context, 'escape' => false]);
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
