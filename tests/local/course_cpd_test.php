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
 * Tests for releasing course completions to CPD logbooks.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local;

use local_cpdlog\persistent\category;
use local_cpdlog\persistent\entry;
use local_cpdlog\task\notify_completions_waiting;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for releasing course completions to CPD logbooks.
 */
#[CoversClass(course_cpd::class)]
#[CoversClass(notify_completions_waiting::class)]
final class course_cpd_test extends \advanced_testcase
{
    /** @var \stdClass A member. */
    private \stdClass $member;

    /** @var \stdClass An approver. */
    private \stdClass $approver;

    /**
     * Returns a timestamp in Sydney.
     *
     * @param string $datetime A date and time.
     * @return int
     */
    private static function sydney(string $datetime): int {
        return (new \DateTimeImmutable($datetime, new \DateTimeZone('Australia/Sydney')))->getTimestamp();
    }

    /**
     * Sets up the 2026 period, a member and an approver.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Australia/Sydney');
        $CFG->enablecompletion = 1;
        $this->getDataGenerator()->get_plugin_generator('local_cpdlog')
            ->create_period(['name' => '2026', 'firstday' => '01/01/2026', 'lastday' => '31/12/2026']);
        $this->member = $this->getDataGenerator()->create_user();
        $this->approver = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/cpdlog:approve', CAP_ALLOW, $roleid, \context_system::instance());
        role_assign($roleid, $this->approver->id, \context_system::instance());
    }

    /**
     * Returns the category field's option position for a category, as stored on a course.
     *
     * @param string $shortname The category short name.
     * @return int
     */
    private static function option(string $shortname): int {
        $options = course_cpd::get_field(course_cpd::FIELD_CATEGORY)->get_options();
        foreach ($options as $index => $label) {
            if (str_ends_with($label, '(' . $shortname . ')')) {
                return $index;
            }
        }
        throw new \coding_exception('No option for ' . $shortname);
    }

    /**
     * Creates a course that awards CPD.
     *
     * @param float $hours The CPD hours.
     * @param string|null $category The CPD category short name, or null for none.
     * @return \stdClass
     */
    private function create_cpd_course(float $hours, ?string $category = null): \stdClass {
        // The fields are locked, so only someone who may change locked fields (here the admin) sets them.
        $this->setAdminUser();
        $record = ['fullname' => 'Dermoscopy basics', 'enablecompletion' => 1, 'customfield_' . course_cpd::FIELD_HOURS => $hours];
        if ($category !== null) {
            $record['customfield_' . course_cpd::FIELD_CATEGORY] = self::option($category);
        }
        return $this->getDataGenerator()->create_course($record);
    }

    /**
     * Records a course completion directly, as on a site that had it before the logbook.
     *
     * @param int $courseid The course.
     * @param int $time When it was completed.
     * @param int|null $userid The member; default the test member.
     */
    private function insert_completion(int $courseid, int $time, ?int $userid = null): void {
        global $DB;
        $DB->insert_record('course_completions', (object) [
            'userid' => $userid ?? $this->member->id,
            'course' => $courseid,
            'timeenrolled' => 0,
            'timestarted' => 0,
            'timecompleted' => $time,
        ]);
    }

    /**
     * Returns the member's entry for a course, if any.
     *
     * @param int $courseid The course.
     * @return entry|false
     */
    private function entry_for(int $courseid) {
        return entry::get_record(['userid' => $this->member->id, 'courseid' => $courseid]);
    }

    /**
     * Installing creates the two locked course fields; the category options list every category in
     * creation order and only grow, so stored positions keep their meaning.
     */
    public function test_fields(): void {
        $hours = course_cpd::get_field(course_cpd::FIELD_HOURS);
        $category = course_cpd::get_field(course_cpd::FIELD_CATEGORY);
        $this->assertSame('number', $hours->get('type'));
        $this->assertSame('select', $category->get('type'));
        $this->assertEquals(1, $hours->get_configdata_property('locked'));
        $this->assertSame(
            ['', 'Educational activities (EA)', 'Reviewing performance (RP)', 'Measuring outcomes (MO)'],
            $category->get_options()
        );

        // A new category is added at the end; a renamed one keeps its position.
        $generated = new category(0, (object) ['name' => 'Clinical audit', 'shortname' => 'CA', 'sortorder' => -1]);
        $generated->create();
        $this->assertSame('Clinical audit (CA)', course_cpd::get_field(course_cpd::FIELD_CATEGORY)->get_options()[4]);
        $rp = category::get_record(['shortname' => 'RP']);
        $rp->set('name', 'Reviewing practice');
        $rp->update();
        $options = course_cpd::get_field(course_cpd::FIELD_CATEGORY)->get_options();
        $this->assertSame('Reviewing practice (RP)', $options[2]);
        $this->assertSame('Clinical audit (CA)', $options[4]);

        // Running set-up again changes nothing.
        course_cpd::setup_fields();
        $this->assertCount(5, course_cpd::get_field(course_cpd::FIELD_CATEGORY)->get_options());
    }

    /**
     * A course's CPD is its hours and chosen category, or the default category when none is usable.
     */
    public function test_get_course_cpd(): void {
        $course = $this->create_cpd_course(2.5, 'RP');
        $cpd = course_cpd::get_course_cpd((int) $course->id);
        $this->assertEquals(2.5, $cpd->hours);
        $this->assertSame('RP', $cpd->category->get('shortname'));

        $this->assertSame('EA', course_cpd::get_course_cpd((int) $this->create_cpd_course(1)->id)->category->get('shortname'));
        set_config('completioncategory', 'MO', 'local_cpdlog');
        $this->assertSame('MO', course_cpd::get_course_cpd((int) $this->create_cpd_course(1)->id)->category->get('shortname'));

        $rp = category::get_record(['shortname' => 'RP']);
        $rp->set('enabled', 0);
        $rp->update();
        $this->assertSame('MO', course_cpd::get_course_cpd((int) $course->id)->category->get('shortname'));

        $this->assertNull(course_cpd::get_course_cpd((int) $this->create_cpd_course(0)->id));
        $this->assertNull(course_cpd::get_course_cpd((int) $this->getDataGenerator()->create_course()->id));
    }

    /**
     * Releasing a completion creates an approved entry reviewed by the staff member, and tells the
     * member; the same completion is never released twice, even after its entry is gone.
     */
    public function test_release(): void {
        $sink = $this->redirectMessages();
        $course = $this->create_cpd_course(2.5, 'RP');
        $this->insert_completion((int) $course->id, self::sydney('2026-03-10 14:00'));
        $events = $this->redirectEvents();

        $result = course_cpd::release((int) $this->member->id, (int) $course->id, (int) $this->approver->id);
        $this->assertSame('released', $result);
        $entry = $this->entry_for((int) $course->id);
        $this->assertSame(entry::STATUS_APPROVED, $entry->get('status'));
        $this->assertSame(entry::SOURCE_MOODLE, $entry->get('source'));
        $this->assertEquals(2.5, $entry->get('hours'));
        $this->assertEquals(self::sydney('2026-03-10 14:00'), $entry->get('activitydate'));
        $this->assertSame('RP', (new category($entry->get('categoryid')))->get('shortname'));
        $this->assertSame('Dermoscopy basics', $entry->get('coursename'));
        $this->assertSame('Completed the course Dermoscopy basics.', $entry->get('description'));
        $this->assertEquals($this->approver->id, $entry->get('reviewedby'));
        $this->assertGreaterThan(0, $entry->get('timereviewed'));
        $this->assertSame(
            [\local_cpdlog\event\entry_created::class, \local_cpdlog\event\entry_approved::class],
            array_values(array_filter(
                array_map('get_class', $events->get_events()),
                fn($class) => str_starts_with($class, 'local_cpdlog')
            ))
        );
        $messages = $sink->get_messages();
        $this->assertEquals([$this->member->id], array_column($messages, 'useridto'));
        $this->assertSame('entryoutcome', $messages[0]->eventtype);
        $this->assertSame([], course_cpd::get_waiting((int) $course->id));

        $this->assertSame('exists', course_cpd::release((int) $this->member->id, (int) $course->id, (int) $this->approver->id));
        $entry->delete();
        $this->assertSame('exists', course_cpd::release((int) $this->member->id, (int) $course->id, (int) $this->approver->id));
        $this->assertFalse(course_cpd::exclude((int) $this->member->id, (int) $course->id, (int) $this->approver->id));
        $this->assertSame([], course_cpd::get_waiting((int) $course->id));
    }

    /**
     * An excluded member leaves the waiting list, is remembered with who excluded them, and can be
     * released later.
     */
    public function test_exclude_then_release(): void {
        global $DB;
        $this->redirectMessages();
        $course = $this->create_cpd_course(1);
        $this->insert_completion((int) $course->id, self::sydney('2026-02-01 10:00'));
        $staff = (int) $this->approver->id;

        $this->assertTrue(course_cpd::exclude((int) $this->member->id, (int) $course->id, $staff));
        $this->assertFalse(course_cpd::exclude((int) $this->member->id, (int) $course->id, $staff));
        $this->assertSame([], course_cpd::get_waiting((int) $course->id));
        $this->assertSame([], course_cpd::count_waiting());
        $excluded = course_cpd::get_excluded((int) $course->id);
        $this->assertSame([(int) $this->member->id], array_map('intval', array_keys($excluded)));
        $row = reset($excluded);
        $this->assertSame($this->approver->firstname, $row->stafffirstname);
        $this->assertSame($this->member->lastname, $row->lastname);
        $this->assertFalse($this->entry_for((int) $course->id));

        $this->assertSame('released', course_cpd::release((int) $this->member->id, (int) $course->id, $staff));
        $this->assertNotFalse($this->entry_for((int) $course->id));
        $this->assertSame([], course_cpd::get_excluded((int) $course->id));
        $log = $DB->get_record(course_cpd::TABLE, ['userid' => $this->member->id, 'courseid' => $course->id]);
        $this->assertSame(course_cpd::STATUS_RELEASED, $log->status);
        $this->assertEquals($this->entry_for((int) $course->id)->get('id'), $log->entryid);

        // Nothing to exclude without a completion.
        $this->assertFalse(course_cpd::exclude((int) $this->approver->id, (int) $course->id, $staff));
    }

    /**
     * The waiting list and counts include past completions, flag those no open period covers, and
     * leave out deleted members, members whose CPD data deletion is queued, and courses without CPD.
     */
    public function test_waiting(): void {
        $course = $this->create_cpd_course(1);
        $this->insert_completion((int) $course->id, self::sydney('2026-02-01 10:00'));
        $early = $this->getDataGenerator()->create_user(['lastname' => 'Aardvark']);
        $this->insert_completion((int) $course->id, self::sydney('2025-06-01 10:00'), (int) $early->id);
        $gone = $this->getDataGenerator()->create_user();
        $this->insert_completion((int) $course->id, self::sydney('2026-02-01 10:00'), (int) $gone->id);
        delete_user($gone);
        $leaving = $this->getDataGenerator()->create_user();
        $this->insert_completion((int) $course->id, self::sydney('2026-02-01 10:00'), (int) $leaving->id);
        set_config('enabledeletion', 1, 'local_cpdlog');
        data_deleter::queue((int) $leaving->id, (int) get_admin()->id);
        $nocpd = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->insert_completion((int) $nocpd->id, self::sydney('2026-02-01 10:00'));
        $notcompleted = $this->getDataGenerator()->create_user();
        $this->insert_completion((int) $course->id, 0, (int) $notcompleted->id);

        $waiting = course_cpd::get_waiting((int) $course->id);
        $this->assertSame([(int) $early->id, (int) $this->member->id], array_map('intval', array_keys($waiting)));
        $this->assertFalse($waiting[$early->id]->releasable);
        $this->assertTrue($waiting[$this->member->id]->releasable);
        $this->assertSame([(int) $course->id => 2], course_cpd::count_waiting());

        // Without a period it cannot be released; once one covers it, it can.
        $this->assertSame('skipped', course_cpd::release((int) $early->id, (int) $course->id, (int) $this->approver->id));
        $this->getDataGenerator()->get_plugin_generator('local_cpdlog')
            ->create_period(['name' => '2025', 'firstday' => '01/01/2025', 'lastday' => '31/12/2025']);
        $this->assertTrue(course_cpd::get_waiting((int) $course->id)[$early->id]->releasable);
        $this->redirectMessages();
        $this->assertSame('released', course_cpd::release((int) $early->id, (int) $course->id, (int) $this->approver->id));
        $this->assertSame([(int) $course->id => 1], course_cpd::count_waiting());
    }

    /**
     * Nothing is released while switched off, without a completion, for a course without CPD, for a
     * member with a queued deletion or completions before a deletion ran, or in a closed period.
     */
    public function test_release_skipped(): void {
        $this->redirectMessages();
        $course = $this->create_cpd_course(1);
        $this->insert_completion((int) $course->id, self::sydney('2026-02-01 10:00'));
        $member = (int) $this->member->id;
        $staff = (int) $this->approver->id;

        set_config('completionenabled', 0, 'local_cpdlog');
        $this->assertSame('skipped', course_cpd::release($member, (int) $course->id, $staff));
        set_config('completionenabled', 1, 'local_cpdlog');

        $this->assertSame('skipped', course_cpd::release($staff, (int) $course->id, $staff));
        $nocpd = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->insert_completion((int) $nocpd->id, self::sydney('2026-02-01 10:00'));
        $this->assertSame('skipped', course_cpd::release($member, (int) $nocpd->id, $staff));

        $period = \local_cpdlog\persistent\period::get_record(['name' => '2026']);
        $period->set('status', \local_cpdlog\persistent\period::STATUS_CLOSED);
        $period->update();
        $this->assertSame('skipped', course_cpd::release($member, (int) $course->id, $staff));
        $this->assertFalse(course_cpd::get_waiting((int) $course->id)[$member]->releasable);
        $period->set('status', \local_cpdlog\persistent\period::STATUS_OPEN);
        $period->update();

        set_config('enabledeletion', 1, 'local_cpdlog');
        data_deleter::queue($member, (int) get_admin()->id);
        $this->assertSame('skipped', course_cpd::release($member, (int) $course->id, $staff));
        $this->runAdhocTasks(\local_cpdlog\task\delete_member_data::class);
        $this->assertSame('skipped', course_cpd::release($member, (int) $course->id, $staff));
        $this->assertSame([], course_cpd::get_waiting((int) $course->id));
        $this->assertFalse($this->entry_for((int) $course->id));

        // A course completed after the deletion ran can be released.
        $later = $this->create_cpd_course(1);
        $this->insert_completion((int) $later->id, time() + 60);
        $this->assertSame('released', course_cpd::release($member, (int) $later->id, $staff));
    }

    /**
     * Approvers are told once about new completions waiting, not again for the same ones, and again
     * when more arrive.
     */
    public function test_notify_waiting(): void {
        $sink = $this->redirectMessages();
        $course = $this->create_cpd_course(1);
        $now = self::sydney('2026-03-01 08:00');
        $this->insert_completion((int) $course->id, $now - DAYSECS);

        $this->assertSame(1, course_cpd::notify_waiting($now));
        $messages = $sink->get_messages();
        $this->assertEquals([$this->approver->id], array_column($messages, 'useridto'));
        $this->assertSame('completionswaiting', $messages[0]->eventtype);
        $this->assertStringContainsString('Dermoscopy basics: 1 waiting', $messages[0]->fullmessagehtml);
        $sink->clear();

        $this->assertSame(0, course_cpd::notify_waiting($now + DAYSECS));
        $other = $this->getDataGenerator()->create_user();
        $this->insert_completion((int) $course->id, $now + HOURSECS, (int) $other->id);
        $this->assertSame(1, course_cpd::notify_waiting($now + 2 * DAYSECS));
        $this->assertStringContainsString('Dermoscopy basics: 2 waiting', $sink->get_messages()[0]->fullmessagehtml);

        // Excluding a new completion means it no longer waits, so it prompts no notice.
        $sink->clear();
        $third = $this->getDataGenerator()->create_user();
        $this->insert_completion((int) $course->id, $now + 3 * DAYSECS, (int) $third->id);
        course_cpd::exclude((int) $third->id, (int) $course->id, (int) $this->approver->id);
        $this->assertSame(0, course_cpd::notify_waiting($now + 4 * DAYSECS));

        set_config('completionenabled', 0, 'local_cpdlog');
        $this->assertSame(0, course_cpd::notify_waiting($now + 5 * DAYSECS));
        $this->assertCount(0, $sink->get_messages());
    }

    /**
     * The daily task reports how many approvers it told, and does nothing while switched off.
     */
    public function test_task(): void {
        $this->redirectMessages();
        $course = $this->create_cpd_course(1);
        $this->insert_completion((int) $course->id, self::sydney('2026-02-01 10:00'));
        $this->expectOutputRegex('/Approvers told about course completions waiting to be released: 1\./');
        (new notify_completions_waiting())->execute();

        set_config('completionenabled', 0, 'local_cpdlog');
        (new notify_completions_waiting())->execute();
    }

    /**
     * Course hours above the site's maximum per entry are capped at it.
     */
    public function test_max_hours(): void {
        set_config('maxhoursperentry', 2, 'local_cpdlog');
        $course = $this->create_cpd_course(5);
        $this->assertEquals(2, course_cpd::get_course_cpd((int) $course->id)->hours);
    }

    /**
     * Uninstalling removes the two course fields and their category.
     */
    public function test_remove_fields(): void {
        global $DB;
        $this->create_cpd_course(2);
        course_cpd::remove_fields();

        $this->assertNull(course_cpd::get_field(course_cpd::FIELD_HOURS));
        $this->assertNull(course_cpd::get_field(course_cpd::FIELD_CATEGORY));
        $this->assertFalse($DB->record_exists('customfield_category', ['name' => 'CPD logbook', 'component' => 'core_course']));
        $this->assertSame(0, $DB->count_records('customfield_data'));
    }
}
