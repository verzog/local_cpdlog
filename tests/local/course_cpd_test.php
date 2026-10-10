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
 * Tests for creating CPD entries automatically from course completions.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local;

use local_cpdlog\observer;
use local_cpdlog\persistent\category;
use local_cpdlog\persistent\entry;
use local_cpdlog\task\award_course_cpd;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for creating CPD entries automatically from course completions.
 */
#[CoversClass(course_cpd::class)]
#[CoversClass(observer::class)]
#[CoversClass(award_course_cpd::class)]
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
     * Completing a course that awards CPD submits an entry for approval and tells approvers, once.
     */
    public function test_completion_creates_entry(): void {
        global $CFG;
        require_once($CFG->dirroot . '/completion/completion_completion.php');
        $sink = $this->redirectMessages();
        $course = $this->create_cpd_course(2.5, 'RP');
        $completion = new \completion_completion(['userid' => $this->member->id, 'course' => $course->id]);
        $completion->mark_complete(self::sydney('2026-03-10 14:00'));

        $entry = $this->entry_for((int) $course->id);
        $this->assertSame(entry::STATUS_SUBMITTED, $entry->get('status'));
        $this->assertSame(entry::SOURCE_MOODLE, $entry->get('source'));
        $this->assertEquals(2.5, $entry->get('hours'));
        $this->assertEquals(self::sydney('2026-03-10 14:00'), $entry->get('activitydate'));
        $this->assertSame('RP', (new category($entry->get('categoryid')))->get('shortname'));
        $this->assertSame('Dermoscopy basics', $entry->get('coursename'));
        $this->assertSame('Completed the course Dermoscopy basics.', $entry->get('description'));
        // Core also tells the member they completed the course; the logbook tells the approver.
        $ours = array_filter($sink->get_messages(), fn($message) => $message->component === 'local_cpdlog');
        $this->assertEquals([$this->approver->id], array_column($ours, 'useridto'));

        // The same completion never creates a second entry, even after the first is gone.
        $this->assertSame('exists', course_cpd::award((int) $this->member->id, (int) $course->id, time(), false));
        $entry->delete();
        $this->assertSame('exists', course_cpd::award((int) $this->member->id, (int) $course->id, time(), false));
        $this->assertSame(0, course_cpd::catch_up()->created);
    }

    /**
     * The task catches up on past completions without notifying approvers, and waits for a period.
     */
    public function test_catch_up(): void {
        $sink = $this->redirectMessages();
        $course = $this->create_cpd_course(3);
        $this->insert_completion((int) $course->id, self::sydney('2026-02-01 10:00'));
        $other = $this->getDataGenerator()->create_user();
        $this->insert_completion((int) $course->id, self::sydney('2025-06-01 10:00'), (int) $other->id);
        $this->insert_completion((int) $this->getDataGenerator()->create_course(['enablecompletion' => 1])->id, time());

        $this->expectOutputRegex('/1 CPD entries created/');
        (new award_course_cpd())->execute();
        $this->assertNotFalse($this->entry_for((int) $course->id));
        $this->assertCount(0, $sink->get_messages());
        $this->assertSame(0, course_cpd::catch_up()->created);

        // The 2025 completion is picked up once a period covers it.
        $this->getDataGenerator()->get_plugin_generator('local_cpdlog')
            ->create_period(['name' => '2025', 'firstday' => '01/01/2025', 'lastday' => '31/12/2025']);
        $this->assertSame(1, course_cpd::catch_up()->created);
    }

    /**
     * The batch limit applies, and the rest are created on the next run. Completions that cannot be
     * awarded yet do not use up the limit.
     */
    public function test_catch_up_limit(): void {
        $course = $this->create_cpd_course(1);
        $early = $this->getDataGenerator()->create_user();
        $this->insert_completion((int) $course->id, self::sydney('2024-01-01 10:00'), (int) $early->id);
        foreach (range(1, 3) as $i) {
            $user = $this->getDataGenerator()->create_user();
            $this->insert_completion((int) $course->id, self::sydney('2026-02-0' . $i . ' 10:00'), (int) $user->id);
        }
        $this->assertSame(2, course_cpd::catch_up(2)->created);
        $this->assertSame(1, course_cpd::catch_up(2)->created);
    }

    /**
     * Nothing is created while switched off, for a member with a queued deletion, or for completions
     * before a deletion ran; completions after it are.
     */
    public function test_not_created(): void {
        $course = $this->create_cpd_course(1);
        $this->insert_completion((int) $course->id, self::sydney('2026-02-01 10:00'));

        set_config('completionenabled', 0, 'local_cpdlog');
        $this->assertSame(0, course_cpd::catch_up()->created);
        set_config('completionenabled', 1, 'local_cpdlog');

        set_config('enabledeletion', 1, 'local_cpdlog');
        data_deleter::queue((int) $this->member->id, (int) get_admin()->id);
        $this->assertSame(0, course_cpd::catch_up()->created);
        $this->assertSame('skipped', course_cpd::award((int) $this->member->id, (int) $course->id, time(), false));
        $this->runAdhocTasks(\local_cpdlog\task\delete_member_data::class);
        $this->assertSame(0, course_cpd::catch_up()->created);

        $later = $this->create_cpd_course(1);
        $this->assertSame('created', course_cpd::award((int) $this->member->id, (int) $later->id, time() + 60, false));
    }

    /**
     * A completion in a closed period waits, as its entry could not be reviewed, and follows once it reopens.
     */
    public function test_closed_period_waits(): void {
        $course = $this->create_cpd_course(1);
        $this->insert_completion((int) $course->id, self::sydney('2026-02-01 10:00'));
        $period = \local_cpdlog\persistent\period::get_record(['name' => '2026']);
        $period->set('status', \local_cpdlog\persistent\period::STATUS_CLOSED);
        $period->update();

        $this->assertSame(0, course_cpd::catch_up()->created);
        $completed = self::sydney('2026-02-01 10:00');
        $this->assertSame('skipped', course_cpd::award((int) $this->member->id, (int) $course->id, $completed, false));

        $period->set('status', \local_cpdlog\persistent\period::STATUS_OPEN);
        $period->update();
        $this->assertSame(1, course_cpd::catch_up()->created);
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
     * Switched off, nothing about the course is looked up; and a completion never fails because the
     * logbook cannot record it.
     */
    public function test_never_breaks_completion(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/completion/completion_completion.php');
        $this->redirectMessages();
        $course = $this->create_cpd_course(1);
        $DB->set_field(category::TABLE, 'enabled', 0, []);

        set_config('completionenabled', 0, 'local_cpdlog');
        $this->assertSame('none', course_cpd::award((int) $this->member->id, (int) $course->id, time(), false));

        set_config('completionenabled', 1, 'local_cpdlog');
        $completion = new \completion_completion(['userid' => $this->member->id, 'course' => $course->id]);
        $completion->mark_complete(self::sydney('2026-03-10 14:00'));
        $this->assertDebuggingCalled();
        $this->assertNotEmpty($completion->timecompleted);
        $this->assertFalse($this->entry_for((int) $course->id));
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
