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
 * Tests for upcoming CPD on the logbook.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local;

use local_cpdlog\local\upcoming\moodle_source;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for upcoming CPD on the logbook.
 */
#[CoversClass(upcoming::class)]
#[CoversClass(moodle_source::class)]
final class upcoming_test extends \advanced_testcase
{
    /** @var int The time the tests run at: 10:00 on 01/03/2026 in Sydney. */
    private int $now;

    /** @var \stdClass The member. */
    private \stdClass $member;

    /** @var \stdClass[] Courses by name. */
    private array $courses = [];

    /**
     * Returns a time in Sydney.
     *
     * @param string $datetime A date and time.
     * @return int
     */
    private static function sydney(string $datetime): int {
        return (new \DateTimeImmutable($datetime, new \DateTimeZone('Australia/Sydney')))->getTimestamp();
    }

    /**
     * Sets up a member, courses that award CPD (one enrolled, one not, one hidden, one in another
     * category) and one that does not.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Australia/Sydney');
        $this->now = self::sydney('2026-03-01 10:00');
        $generator = $this->getDataGenerator();
        $this->member = $generator->create_user();
        $other = $generator->create_category(['name' => 'Workshops']);

        // The CPD fields are locked, so only someone who may change locked fields (here the admin) sets them.
        $this->setAdminUser();
        $courses = [
            'enrolled' => ['startdate' => self::sydney('2026-03-11'), 'hours' => 2.5],
            'open' => ['startdate' => self::sydney('2026-03-06'), 'hours' => 1, 'category' => $other->id],
            'hidden' => ['startdate' => self::sydney('2026-03-06'), 'hours' => 1, 'visible' => 0],
            'nocpd' => ['startdate' => self::sydney('2026-03-06'), 'hours' => 0],
        ];
        foreach ($courses as $name => $settings) {
            $record = [
                'fullname' => ucfirst($name) . ' course',
                'startdate' => $settings['startdate'],
                'visible' => $settings['visible'] ?? 1,
                'customfield_' . course_cpd::FIELD_HOURS => $settings['hours'],
            ];
            if (isset($settings['category'])) {
                $record['category'] = $settings['category'];
            }
            $this->courses[$name] = $generator->create_course($record);
        }
        $generator->enrol_user($this->member->id, $this->courses['enrolled']->id);

        // A visible course in a hidden category is not listed either.
        $hiddencategory = $generator->create_category(['name' => 'Archive', 'visible' => 0]);
        $generator->create_course([
            'fullname' => 'Archived course',
            'category' => $hiddencategory->id,
            'startdate' => self::sydney('2026-03-06'),
            'customfield_' . course_cpd::FIELD_HOURS => 1,
        ]);
    }

    /**
     * Adds a calendar event.
     *
     * @param string $name Its name.
     * @param string $when When it starts, in Sydney.
     * @param string|null $course The course it is in, by name; null for a category event.
     * @param int $categoryid The category, for a category event.
     */
    private function event(string $name, string $when, ?string $course, int $categoryid = 0): void {
        $this->getDataGenerator()->create_event([
            'name' => $name,
            'timestart' => self::sydney($when),
            'eventtype' => $course ? 'course' : 'category',
            'courseid' => $course ? $this->courses[$course]->id : 0,
            'categoryid' => $categoryid,
        ]);
    }

    /**
     * Returns the titles in each list.
     *
     * @param array[] $lists The lists.
     * @return array[]
     */
    private static function titles(array $lists): array {
        return array_map(fn($list) => array_column($list, 'title'), $lists);
    }

    /**
     * Members see what is coming up in their own CPD courses, and in other visible CPD courses,
     * soonest first, within the window.
     */
    public function test_lists(): void {
        $this->event('Webinar', '2026-03-04 18:30', 'enrolled');
        $this->event('Earlier today', '2026-03-01 08:00', 'enrolled');
        $this->event('Yesterday', '2026-02-28 18:00', 'enrolled');
        $this->event('Too far ahead', '2026-05-01 18:00', 'enrolled');
        $this->event('Open day', '2026-03-20 09:00', 'open');
        $this->event('Hidden event', '2026-03-05 09:00', 'hidden');
        $this->event('Not CPD', '2026-03-05 09:00', 'nocpd');
        $this->event('Workshop week', '2026-03-09 09:00', null, (int) $this->courses['open']->category);
        $this->event('Faculty meeting', '2026-03-09 09:00', null, (int) $this->courses['enrolled']->category);

        $lists = upcoming::get_lists((int) $this->member->id, $this->now);
        $this->assertSame([
            'mine' => ['Earlier today', 'Webinar', 'Faculty meeting', 'Enrolled course'],
            'available' => ['Open course', 'Workshop week', 'Open day'],
        ], self::titles($lists));

        $webinar = $lists['mine'][1];
        $this->assertSame('04/03/2026, 06:30 PM', $webinar['date']);
        $this->assertFalse($webinar['iscourse']);
        $this->assertSame('Enrolled course', $webinar['coursename']);
        $this->assertSame('2.50', $webinar['hours']);
        $this->assertSame('Educational activities', $webinar['category']);
        $this->assertStringContainsString('/course/view.php?id=' . $this->courses['enrolled']->id, $webinar['url']);

        $start = $lists['mine'][3];
        $this->assertTrue($start['iscourse']);
        $this->assertSame('11/03/2026', $start['date']);

        // A category event belongs to no course, and links to the calendar.
        $category = $lists['mine'][2];
        $this->assertSame('', $category['coursename']);
        $this->assertSame('', $category['hours']);
        $this->assertStringContainsString('/calendar/view.php', $category['url']);
    }

    /**
     * The settings set the window and the length of each list, and can switch the panel off.
     */
    public function test_settings(): void {
        $this->assertTrue(upcoming::is_enabled());
        set_config('upcomingenabled', 0, 'local_cpdlog');
        $this->assertFalse(upcoming::is_enabled());

        foreach (range(1, 4) as $day) {
            $this->event('Session ' . $day, '2026-03-0' . (1 + $day) . ' 18:00', 'enrolled');
        }
        $userid = (int) $this->member->id;
        set_config('upcominglimit', 2, 'local_cpdlog');
        $this->assertSame(['Session 1', 'Session 2'], self::titles(upcoming::get_lists($userid, $this->now))['mine']);

        // The cached list is for one window, so a new window loads afresh. Four days run to 05/03 00:00.
        set_config('upcomingdays', 4, 'local_cpdlog');
        set_config('upcominglimit', 50, 'local_cpdlog');
        $lists = self::titles(upcoming::get_lists($userid, $this->now));
        $this->assertSame(['Session 1', 'Session 2', 'Session 3'], $lists['mine']);
        $this->assertSame([], $lists['available']);

        set_config('upcomingdays', 0, 'local_cpdlog');
        $this->assertSame(upcoming::DEFAULT_DAYS, upcoming::get_days());
        set_config('upcominglimit', 500, 'local_cpdlog');
        $this->assertSame(50, upcoming::get_limit());
    }

    /**
     * The window runs whole calendar days in the site timezone, across a daylight saving change.
     */
    public function test_window(): void {
        set_config('upcomingdays', 60, 'local_cpdlog');
        [$from, $to] = upcoming::get_window(self::sydney('2026-03-01 23:59'));
        $this->assertSame(self::sydney('2026-03-01 00:00'), $from);
        // Daylight saving ends on 05/04/2026, so this window is an hour longer than 60 x 24 hours.
        $this->assertSame(self::sydney('2026-04-30 00:00'), $to);
        $this->assertSame(60 * DAYSECS + HOURSECS, $to - $from);
    }

    /**
     * The list is cached for everyone, so new events show once it expires; each member's own items
     * are still worked out afresh.
     */
    public function test_cache(): void {
        $this->event('Webinar', '2026-03-04 18:30', 'enrolled');
        $this->assertCount(2, upcoming::get_lists((int) $this->member->id, $this->now)['mine']);

        $this->event('Second webinar', '2026-03-05 18:30', 'enrolled');
        $this->assertCount(2, upcoming::get_lists((int) $this->member->id, $this->now)['mine']);

        // Enrolling counts at once, from the cached list.
        $this->getDataGenerator()->enrol_user($this->member->id, $this->courses['open']->id);
        $this->assertSame([], upcoming::get_lists((int) $this->member->id, $this->now)['available']);

        \cache::make('local_cpdlog', 'upcoming')->purge();
        $this->assertCount(4, upcoming::get_lists((int) $this->member->id, $this->now)['mine']);
    }

    /**
     * Event names are shown as plain text, with filters applied but not escaped, for the template to escape.
     */
    public function test_plain_text(): void {
        $this->event('Skin <b>&amp;</b> sun', '2026-03-04 18:30', 'enrolled');
        $this->assertSame('Skin & sun', upcoming::get_lists((int) $this->member->id, $this->now)['mine'][0]['title']);
    }
}
