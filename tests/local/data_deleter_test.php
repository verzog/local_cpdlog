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
 * Tests for staff deletion of a member's CPD data.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local;

use local_cpdlog\event\member_data_deleted;
use local_cpdlog\persistent\category;
use local_cpdlog\persistent\entry;
use local_cpdlog\persistent\period;
use local_cpdlog\task\delete_member_data;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for staff deletion of a member's CPD data.
 */
#[CoversClass(data_deleter::class)]
#[CoversClass(delete_member_data::class)]
#[CoversClass(member_data_deleted::class)]
final class data_deleter_test extends \advanced_testcase
{
    /** @var \stdClass The member whose data is deleted. */
    private \stdClass $member;

    /** @var \stdClass Another member, whose data must be kept. */
    private \stdClass $other;

    /** @var \stdClass The staff member asking for deletions. */
    private \stdClass $admin;

    /** @var period The 2026 period. */
    private period $period;

    /**
     * Sets up a period and two members with entries and evidence.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Australia/Sydney');
        $this->admin = get_admin();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_cpdlog');
        $this->period = $generator->create_period(['name' => '2026', 'firstday' => '01/01/2026', 'lastday' => '31/12/2026']);
        $this->member = $this->getDataGenerator()->create_user(['username' => 'member1']);
        $this->other = $this->getDataGenerator()->create_user(['username' => 'other1']);

        foreach ([$this->member, $this->other] as $user) {
            $generator->create_entry(['userid' => $user->id, 'period' => '2026', 'status' => entry::STATUS_DRAFT]);
            $generator->create_entry([
                'userid' => $user->id,
                'period' => '2026',
                'status' => entry::STATUS_APPROVED,
                'evidence' => 'certificate.pdf, notes.pdf',
            ]);
        }
        $this->add_choice((int) $this->member->id, (int) $this->admin->id);
    }

    /**
     * Records a cohort choice for a member directly.
     *
     * @param int $userid The member.
     * @param int $chosenby The staff member.
     */
    private function add_choice(int $userid, int $chosenby): void {
        global $DB;
        $DB->insert_record(target_resolver::CHOICE_TABLE, (object) [
            'userid' => $userid,
            'periodid' => $this->period->get('id'),
            'cohortid' => null,
            'chosenby' => $chosenby,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Counts a user's evidence files.
     *
     * @param int $userid The user.
     * @return int
     */
    private function count_files(int $userid): int {
        $files = 0;
        foreach (entry::get_records(['userid' => $userid]) as $entry) {
            $files += entry_manager::count_evidence($entry);
        }
        return $files;
    }

    /**
     * Queuing needs the tool switched on, and only one deletion per member can wait at a time.
     */
    public function test_queue(): void {
        try {
            data_deleter::queue((int) $this->member->id, (int) $this->admin->id);
            $this->fail('A deletion was queued while the tool was switched off.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:deletiondisabled', $e->errorcode);
        }

        set_config('enabledeletion', 1, 'local_cpdlog');
        $id = data_deleter::queue((int) $this->member->id, (int) $this->admin->id);
        $this->assertEquals($id, data_deleter::get_queued((int) $this->member->id)->id);
        $tasks = \core\task\manager::get_adhoc_tasks(delete_member_data::class);
        $this->assertCount(1, $tasks);
        $this->assertEquals($id, reset($tasks)->get_custom_data()->deletionid);

        $this->expectException(\moodle_exception::class);
        data_deleter::queue((int) $this->member->id, (int) $this->admin->id);
    }

    /**
     * The summary counts the member's entries by status, their files and choices, and their staff traces.
     */
    public function test_count(): void {
        $counts = data_deleter::count((int) $this->member->id);
        $this->assertSame([entry::STATUS_APPROVED => 1, entry::STATUS_DRAFT => 1], $this->sorted($counts->entries));
        $this->assertSame(2, $counts->totalentries);
        $this->assertSame(2, $counts->files);
        $this->assertSame(1, $counts->choices);
        $this->assertSame(0, $counts->stafftraces);
        // The admin chose a cohort for the member.
        $this->assertSame(1, data_deleter::count((int) $this->admin->id)->stafftraces);
    }

    /**
     * Sorts an array by key.
     *
     * @param array $values The array.
     * @return array
     */
    private function sorted(array $values): array {
        ksort($values);
        return $values;
    }

    /**
     * The task deletes everything about the member, and nothing about anyone else, then records and logs it.
     */
    public function test_run_deletes_member_data(): void {
        global $DB;
        set_config('enabledeletion', 1, 'local_cpdlog');
        $id = data_deleter::queue((int) $this->member->id, (int) $this->admin->id);
        $sink = $this->redirectEvents();

        $this->runAdhocTasks(delete_member_data::class);

        $this->assertSame(0, entry::count_records(['userid' => $this->member->id]));
        $this->assertSame(0, $DB->count_records(target_resolver::CHOICE_TABLE, ['userid' => $this->member->id]));
        $fs = get_file_storage();
        $remaining = $fs->get_area_files(\context_system::instance()->id, 'local_cpdlog', entry_manager::EVIDENCE_AREA);
        $this->assertCount(2, array_filter($remaining, fn(\stored_file $file) => !$file->is_directory()));
        $this->assertSame(2, entry::count_records(['userid' => $this->other->id]));
        $this->assertSame(2, $this->count_files((int) $this->other->id));

        $deletion = $DB->get_record(data_deleter::TABLE, ['id' => $id]);
        $this->assertSame(data_deleter::STATUS_DONE, $deletion->status);
        $this->assertEquals(2, $deletion->entriesdeleted);
        $this->assertEquals(2, $deletion->filesdeleted);
        $this->assertNotEmpty($deletion->timecompleted);
        $this->assertNull(data_deleter::get_queued((int) $this->member->id));

        $events = array_values(array_filter($sink->get_events(), fn($event) => $event instanceof member_data_deleted));
        $this->assertCount(1, $events);
        $this->assertEquals($this->admin->id, $events[0]->userid);
        $this->assertEquals($this->member->id, $events[0]->relateduserid);
        $this->assertSame(['entriesdeleted' => 2, 'filesdeleted' => 2], $events[0]->other);

        // Running the same deletion again changes nothing.
        data_deleter::run($id);
        $this->assertSame(data_deleter::STATUS_DONE, $DB->get_field(data_deleter::TABLE, 'status', ['id' => $id]));
    }

    /**
     * Deleting a staff member's data clears their name from other members' records but keeps the records.
     */
    public function test_staff_traces_cleared(): void {
        global $DB;
        $staff = $this->getDataGenerator()->create_user();
        $reviewed = entry::get_record(['userid' => $this->other->id, 'status' => entry::STATUS_APPROVED]);
        $DB->update_record(entry::TABLE, (object) [
            'id' => $reviewed->get('id'),
            'reviewedby' => $staff->id,
            'timereviewed' => 1000,
            'reversedby' => $staff->id,
            'usermodified' => $staff->id,
        ]);
        $this->add_choice((int) $this->other->id, (int) $staff->id);
        $this->setUser($staff);
        $category = category::get_record(['shortname' => 'EA']);
        $category->set('name', 'Education');
        $category->update();
        $this->setAdminUser();

        set_config('enabledeletion', 1, 'local_cpdlog');
        // Reviewer, reverser and last editor of one entry, a cohort choice, and a category.
        $this->assertSame(5, data_deleter::count((int) $staff->id)->stafftraces);
        data_deleter::queue((int) $staff->id, (int) $this->admin->id);
        $this->runAdhocTasks(delete_member_data::class);

        $kept = $DB->get_record(entry::TABLE, ['id' => $reviewed->get('id')]);
        $this->assertSame(entry::STATUS_APPROVED, $kept->status);
        $this->assertNull($kept->reviewedby);
        $this->assertNull($kept->reversedby);
        $this->assertEquals(0, $kept->usermodified);
        $this->assertEquals(1000, $kept->timereviewed);
        $choice = $DB->get_record(target_resolver::CHOICE_TABLE, ['userid' => $this->other->id]);
        $this->assertEquals(0, $choice->chosenby);
        $this->assertEquals(0, $DB->get_field(category::TABLE, 'usermodified', ['shortname' => 'EA']));
        $this->assertSame('Education', $DB->get_field(category::TABLE, 'name', ['shortname' => 'EA']));
    }

    /**
     * Switching the tool off cancels a queued deletion instead of running it.
     */
    public function test_switched_off_cancels(): void {
        global $DB;
        set_config('enabledeletion', 1, 'local_cpdlog');
        $id = data_deleter::queue((int) $this->member->id, (int) $this->admin->id);
        set_config('enabledeletion', 0, 'local_cpdlog');

        $this->runAdhocTasks(delete_member_data::class);

        $this->assertSame(data_deleter::STATUS_CANCELLED, $DB->get_field(data_deleter::TABLE, 'status', ['id' => $id]));
        $this->assertSame(2, entry::count_records(['userid' => $this->member->id]));
        $this->assertSame(2, $this->count_files((int) $this->member->id));
    }

    /**
     * Switching the tool off in the settings cancels queued deletions for good, even if it is switched on again.
     */
    public function test_setting_switched_off_cancels(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/adminlib.php');
        $this->setAdminUser();
        set_config('enabledeletion', 1, 'local_cpdlog');
        $id = data_deleter::queue((int) $this->member->id, (int) $this->admin->id);

        admin_write_settings((object) ['s_local_cpdlog_enabledeletion' => 0]);
        $this->assertSame(data_deleter::STATUS_CANCELLED, $DB->get_field(data_deleter::TABLE, 'status', ['id' => $id]));
        $this->assertNull(data_deleter::get_queued((int) $this->member->id));

        admin_write_settings((object) ['s_local_cpdlog_enabledeletion' => 1]);
        $this->runAdhocTasks(delete_member_data::class);

        $this->assertSame(data_deleter::STATUS_CANCELLED, $DB->get_field(data_deleter::TABLE, 'status', ['id' => $id]));
        $this->assertSame(2, entry::count_records(['userid' => $this->member->id]));
        $this->assertSame(2, $this->count_files((int) $this->member->id));
    }

    /**
     * Cancelling touches only queued deletions and reports how many it cancelled.
     */
    public function test_cancel_queued(): void {
        global $DB;
        set_config('enabledeletion', 1, 'local_cpdlog');
        $done = data_deleter::queue((int) $this->other->id, (int) $this->admin->id);
        $this->runAdhocTasks(delete_member_data::class);
        $queued = data_deleter::queue((int) $this->member->id, (int) $this->admin->id);

        $this->assertSame(1, data_deleter::cancel_queued());

        $this->assertSame(data_deleter::STATUS_DONE, $DB->get_field(data_deleter::TABLE, 'status', ['id' => $done]));
        $this->assertSame(data_deleter::STATUS_CANCELLED, $DB->get_field(data_deleter::TABLE, 'status', ['id' => $queued]));
        $this->assertNotEmpty($DB->get_field(data_deleter::TABLE, 'timecompleted', ['id' => $queued]));
        $this->assertSame(0, data_deleter::cancel_queued());
    }

    /**
     * A member whose Moodle account was already deleted can still have their CPD data deleted.
     */
    public function test_deleted_account(): void {
        global $DB;
        $this->getDataGenerator()->get_plugin_generator('local_cpdlog')->create_deleted_account(['user' => 'member1']);
        $this->assertSame(2, entry::count_records(['userid' => $this->member->id]));
        set_config('enabledeletion', 1, 'local_cpdlog');

        $id = data_deleter::queue((int) $this->member->id, (int) $this->admin->id);
        $this->runAdhocTasks(delete_member_data::class);

        $this->assertSame(data_deleter::STATUS_DONE, $DB->get_field(data_deleter::TABLE, 'status', ['id' => $id]));
        $this->assertSame(0, entry::count_records(['userid' => $this->member->id]));
        $this->assertSame(2, entry::count_records(['userid' => $this->other->id]));
    }

    /**
     * Deleting a member's CPD data also clears the reminders sent to them, and only theirs.
     */
    public function test_reminders_deleted(): void {
        global $DB;
        foreach ([$this->member, $this->other] as $user) {
            $DB->insert_record(reminder::TABLE, (object) [
                'userid' => $user->id,
                'periodid' => $this->period->get('id'),
                'daysbefore' => 14,
                'timesent' => time(),
            ]);
        }
        set_config('enabledeletion', 1, 'local_cpdlog');
        data_deleter::queue((int) $this->member->id, (int) $this->admin->id);
        $this->runAdhocTasks(delete_member_data::class);

        $this->assertSame(0, $DB->count_records(reminder::TABLE, ['userid' => $this->member->id]));
        $this->assertSame(1, $DB->count_records(reminder::TABLE, ['userid' => $this->other->id]));
    }
}
