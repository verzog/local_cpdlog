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
 * Tests for the rules members log, edit and submit CPD entries under.
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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the rules members log, edit and submit CPD entries under.
 */
#[CoversClass(entry_manager::class)]
final class entry_manager_test extends \advanced_testcase
{
    /** @var \stdClass The member. */
    private \stdClass $member;

    /** @var \stdClass[] Courses: enrolled, other (never enrolled), suspended, completed (since unenrolled). */
    private array $courses = [];

    /** @var period The open 2026 period. */
    private period $period;

    /**
     * Sets up an open 2026 period, a closed 2025 period, a member and four courses.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Australia/Sydney');
        set_config('maxhoursperentry', 10, 'local_cpdlog');

        $generator = $this->getDataGenerator()->get_plugin_generator('local_cpdlog');
        $this->period = $generator->create_period(['name' => '2026', 'firstday' => '01/01/2026', 'lastday' => '31/12/2026']);
        $generator->create_period([
            'name' => '2025',
            'firstday' => '01/01/2025',
            'lastday' => '31/12/2025',
            'status' => period::STATUS_CLOSED,
        ]);

        $this->member = $this->getDataGenerator()->create_user();
        foreach (['enrolled', 'other', 'suspended', 'completed'] as $name) {
            $this->courses[$name] = $this->getDataGenerator()->create_course(['fullname' => ucfirst($name) . ' course']);
        }
        $this->getDataGenerator()->enrol_user($this->member->id, $this->courses['enrolled']->id);
        $this->getDataGenerator()->enrol_user(
            $this->member->id,
            $this->courses['suspended']->id,
            'student',
            'manual',
            0,
            0,
            ENROL_USER_SUSPENDED
        );
        $DB->insert_record('course_completions', [
            'userid' => $this->member->id,
            'course' => $this->courses['completed']->id,
            'timeenrolled' => 0,
            'timestarted' => 0,
            'timecompleted' => time(),
        ]);
    }

    /**
     * Returns valid entry details, with overrides.
     *
     * @param array $overrides Fields to change.
     * @return \stdClass
     */
    private function details(array $overrides = []): \stdClass {
        return (object) ($overrides + [
            'categoryid' => category::get_record(['shortname' => 'EA'])->get('id'),
            'courseid' => $this->courses['enrolled']->id,
            'activitydate' => self::day('2026-03-10'),
            'hours' => 1.5,
            'description' => 'Skin cancer workshop',
            'descriptionformat' => FORMAT_HTML,
        ]);
    }

    /**
     * Returns midnight at the start of a day in Sydney.
     *
     * @param string $date Local date.
     * @return int
     */
    private static function day(string $date): int {
        return (new \DateTimeImmutable($date, new \DateTimeZone('Australia/Sydney')))->getTimestamp();
    }

    /**
     * Current, suspended and completed courses can be logged; unrelated courses and the site cannot.
     */
    public function test_course_options(): void {
        $options = entry_manager::get_course_options((int) $this->member->id);

        $this->assertEqualsCanonicalizing(
            [$this->courses['enrolled']->id, $this->courses['suspended']->id, $this->courses['completed']->id],
            array_keys($options)
        );
        $this->assertFalse(entry_manager::can_log_course((int) $this->member->id, (int) $this->courses['other']->id));
        $this->assertFalse(entry_manager::can_log_course((int) $this->member->id, SITEID));
        $this->assertTrue(entry_manager::can_log_course((int) $this->member->id, (int) $this->courses['completed']->id));
    }

    /**
     * Each rule reports an error against the field that breaks it.
     */
    public function test_validate(): void {
        $userid = (int) $this->member->id;
        $disabled = category::get_record(['shortname' => 'MO']);
        $disabled->set('enabled', false);
        $disabled->update();

        $this->assertSame([], entry_manager::validate($userid, $this->details()));
        $cases = [
            'courseid' => ['courseid' => $this->courses['other']->id],
            'activitydate' => ['activitydate' => self::day('2025-06-01')],
            'hours' => ['hours' => 10.01],
            'categoryid' => ['categoryid' => $disabled->get('id')],
        ];
        foreach ($cases as $field => $overrides) {
            $this->assertSame([$field], array_keys(entry_manager::validate($userid, $this->details($overrides))), $field);
        }
        foreach ([0, -1, 1.234] as $hours) {
            $this->assertArrayHasKey('hours', entry_manager::validate($userid, $this->details(['hours' => $hours])));
        }
        $nowhere = entry_manager::validate($userid, $this->details(['activitydate' => self::day('2024-06-01')]));
        $this->assertSame(get_string('error:entrynoperiod', 'local_cpdlog'), $nowhere['activitydate']);
    }

    /**
     * Saving works out the period from the date, snapshots the course name and logs the change.
     */
    public function test_save_draft(): void {
        $sink = $this->redirectEvents();
        $entry = entry_manager::save_draft((int) $this->member->id, $this->details(['activitydate' => self::day('2026-12-31')]));

        $this->assertEquals($this->period->get('id'), $entry->get('periodid'));
        $this->assertSame('Enrolled course', $entry->get('coursename'));
        $this->assertSame(entry::STATUS_DRAFT, $entry->get('status'));
        $this->assertSame(entry::SOURCE_MOODLE, $entry->get('source'));
        $this->assertEquals(1.5, $entry->get('hours'));

        entry_manager::save_draft((int) $this->member->id, $this->details(['hours' => 2]), $entry);
        $events = $sink->get_events();
        $this->assertInstanceOf(entry_created::class, $events[0]);
        $this->assertInstanceOf(entry_updated::class, $events[1]);
        $this->assertEquals($this->member->id, $events[0]->relateduserid);
    }

    /**
     * Submitting records the time, logs it, and cannot be repeated.
     */
    public function test_submit(): void {
        $entry = entry_manager::save_draft((int) $this->member->id, $this->details());
        $sink = $this->redirectEvents();

        entry_manager::submit($entry, (int) $this->member->id);

        $saved = new entry($entry->get('id'));
        $this->assertSame(entry::STATUS_SUBMITTED, $saved->get('status'));
        $this->assertNotNull($saved->get('timesubmitted'));
        $this->assertInstanceOf(entry_submitted::class, $sink->get_events()[0]);
        $this->assertFalse(entry_manager::can_edit($saved, (int) $this->member->id));

        $this->expectException(\moodle_exception::class);
        entry_manager::submit($saved, (int) $this->member->id);
    }

    /**
     * Another member cannot edit, submit or delete someone else's entry.
     */
    public function test_other_member_blocked(): void {
        $entry = entry_manager::save_draft((int) $this->member->id, $this->details());
        $otherid = (int) $this->getDataGenerator()->create_user()->id;

        $this->assertFalse(entry_manager::can_edit($entry, $otherid));
        foreach (['save', 'submit', 'delete'] as $action) {
            try {
                match ($action) {
                    'save' => entry_manager::save_draft($otherid, $this->details(), $entry),
                    'submit' => entry_manager::submit($entry, $otherid),
                    'delete' => entry_manager::delete_draft($entry, $otherid),
                };
                $this->fail('Expected an exception for ' . $action);
            } catch (\moodle_exception $e) {
                $this->assertSame('error:entrylocked', $e->errorcode);
            }
        }
        $this->assertSame(entry::STATUS_DRAFT, (new entry($entry->get('id')))->get('status'));
    }

    /**
     * Editing a rejected entry returns it to draft and keeps the reason on record.
     */
    public function test_rejected_entry_returns_to_draft(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_cpdlog');
        $entry = $generator->create_entry([
            'userid' => $this->member->id,
            'periodid' => $this->period->get('id'),
            'courseid' => $this->courses['enrolled']->id,
            'status' => entry::STATUS_REJECTED,
            'rejectionreason' => 'Add the certificate',
        ]);

        $saved = entry_manager::save_draft((int) $this->member->id, $this->details(), $entry);

        $this->assertSame(entry::STATUS_DRAFT, $saved->get('status'));
        $this->assertSame('Add the certificate', $saved->get('rejectionreason'));
    }

    /**
     * Submitted, approved and iMIS entries, and entries in closed periods, cannot be changed.
     */
    public function test_locked_entries(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_cpdlog');
        $userid = (int) $this->member->id;
        $base = ['userid' => $userid, 'periodid' => $this->period->get('id')];
        $closed = period::get_record(['name' => '2025']);

        $locked = [
            $generator->create_entry($base + ['status' => entry::STATUS_SUBMITTED]),
            $generator->create_entry($base + ['status' => entry::STATUS_APPROVED]),
            $generator->create_entry($base + ['source' => entry::SOURCE_IMIS]),
            $generator->create_entry(['userid' => $userid, 'periodid' => $closed->get('id')]),
        ];
        foreach ($locked as $entry) {
            $this->assertFalse(entry_manager::can_edit($entry, $userid));
        }
        $this->assertTrue(entry_manager::can_edit($generator->create_entry($base), $userid));
    }

    /**
     * Only drafts can be deleted, and deleting is logged.
     */
    public function test_delete_draft(): void {
        $entry = entry_manager::save_draft((int) $this->member->id, $this->details());
        $sink = $this->redirectEvents();

        entry_manager::delete_draft($entry, (int) $this->member->id);

        $this->assertFalse(entry::record_exists($entry->get('id')));
        $this->assertInstanceOf(entry_deleted::class, $sink->get_events()[0]);

        $rejected = $this->getDataGenerator()->get_plugin_generator('local_cpdlog')->create_entry([
            'userid' => $this->member->id,
            'periodid' => $this->period->get('id'),
            'status' => entry::STATUS_REJECTED,
        ]);
        $this->expectException(\moodle_exception::class);
        entry_manager::delete_draft($rejected, (int) $this->member->id);
    }

    /**
     * Other entries for the same course on the same day are counted; reversed ones are not.
     */
    public function test_count_duplicates(): void {
        $userid = (int) $this->member->id;
        $first = entry_manager::save_draft($userid, $this->details());
        $this->assertSame(0, entry_manager::count_duplicates($first));

        $second = entry_manager::save_draft($userid, $this->details());
        $this->assertSame(1, entry_manager::count_duplicates($second));

        $first->set('status', entry::STATUS_REVERSED);
        $first->update();
        $this->assertSame(0, entry_manager::count_duplicates($second));
        $this->assertSame(0, entry_manager::count_duplicates(entry_manager::save_draft($userid, $this->details([
            'activitydate' => self::day('2026-03-11'),
        ]))));
    }

    /**
     * A category disabled after drafting stays valid for that entry, so it can still be submitted.
     */
    public function test_kept_category_stays_valid(): void {
        $entry = entry_manager::save_draft((int) $this->member->id, $this->details());
        $category = new category($entry->get('categoryid'));
        $category->set('enabled', false);
        $category->update();

        entry_manager::submit($entry, (int) $this->member->id);

        $this->assertSame(entry::STATUS_SUBMITTED, (new entry($entry->get('id')))->get('status'));
    }
}
