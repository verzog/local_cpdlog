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
 * Tests for the CPD logbook privacy provider.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_cpdlog\persistent\entry;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the CPD logbook privacy provider.
 */
#[CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase
{
    /**
     * Every table holding personal data, the evidence files and the notifications are declared.
     */
    public function test_get_metadata_declares_tables(): void {
        $collection = provider::get_metadata(new collection('local_cpdlog'));
        $names = array_map(fn($item) => $item->get_name(), $collection->get_collection());

        $this->assertEqualsCanonicalizing([
            'core_files',
            'core_message',
            'local_cpdlog_category',
            'local_cpdlog_cohortchoice',
            'local_cpdlog_completion',
            'local_cpdlog_deletion',
            'local_cpdlog_entry',
            'local_cpdlog_period',
            'local_cpdlog_reminder',
            'local_cpdlog_target',
        ], $names);
    }

    /**
     * Every declared table and field has a language string.
     */
    public function test_get_metadata_strings_exist(): void {
        $manager = get_string_manager();
        foreach (provider::get_metadata(new collection('local_cpdlog'))->get_collection() as $item) {
            $this->assertTrue($manager->string_exists($item->get_summary(), 'local_cpdlog'), $item->get_summary());
            foreach ($item->get_privacy_fields() as $identifier) {
                $this->assertTrue($manager->string_exists($identifier, 'local_cpdlog'), $identifier);
            }
        }
    }

    /**
     * Every field declared for the entry table exists in the installed schema.
     */
    public function test_get_metadata_fields_exist(): void {
        global $DB;

        foreach (provider::get_metadata(new collection('local_cpdlog'))->get_collection() as $item) {
            if (!$item instanceof \core_privacy\local\metadata\types\database_table) {
                continue;
            }
            $columns = $DB->get_columns($item->get_name());
            foreach (array_keys($item->get_privacy_fields()) as $field) {
                $this->assertArrayHasKey($field, $columns, $item->get_name() . '.' . $field);
            }
        }
    }

    /**
     * Creates a member with a submitted entry reviewed by a staff member.
     *
     * @return array [member, staff, entry]
     */
    private function create_reviewed_entry(): array {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_cpdlog');
        $member = $this->getDataGenerator()->create_user();
        $staff = $this->getDataGenerator()->create_user();
        $period = $generator->create_period(['name' => '2026', 'firstday' => '01/01/2026', 'lastday' => '31/12/2026']);
        $entry = $generator->create_entry([
            'userid' => $member->id,
            'periodid' => $period->get('id'),
            'hours' => 2.5,
            'description' => 'Dermoscopy course',
            'status' => entry::STATUS_REJECTED,
            'reviewedby' => $staff->id,
            'timereviewed' => time(),
            'rejectionreason' => 'Add the certificate',
            'externalref' => 'IMIS-CPD-42',
            'syncstatus' => 'sent',
            'evidence' => 'certificate.pdf',
        ]);
        return [$member, $staff, $entry];
    }

    /**
     * Members and staff with CPD data have the system context; other users have none.
     */
    public function test_get_contexts_for_userid(): void {
        $this->resetAfterTest();
        [$member, $staff] = $this->create_reviewed_entry();
        $other = $this->getDataGenerator()->create_user();
        $system = \context_system::instance();

        $this->assertEquals([$system->id], provider::get_contexts_for_userid($member->id)->get_contextids());
        $this->assertEquals([$system->id], provider::get_contexts_for_userid($staff->id)->get_contextids());
        $this->assertSame([], provider::get_contexts_for_userid($other->id)->get_contextids());

        $userlist = new userlist($system, 'local_cpdlog');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$member->id, $staff->id], $userlist->get_userids());
    }

    /**
     * A member's export includes their entries; a staff member's includes only the fact of their actions.
     */
    public function test_export_user_data(): void {
        $this->resetAfterTest();
        [$member, $staff, $entry] = $this->create_reviewed_entry();
        $system = \context_system::instance();
        $component = get_string('pluginname', 'local_cpdlog');

        $this->export_context_data_for_user($member->id, $system, 'local_cpdlog');
        $data = writer::with_context($system)->get_data([$component, get_string('privacy:entries', 'local_cpdlog')]);
        $this->assertCount(1, $data->entries);
        $this->assertSame('2.50', $data->entries[0]->hours);
        $this->assertSame('Add the certificate', $data->entries[0]->rejectionreason);
        $this->assertSame('Educational activities', $data->entries[0]->category);
        $this->assertSame('IMIS-CPD-42', $data->entries[0]->externalref);
        $this->assertSame('sent', $data->entries[0]->syncstatus);
        $files = writer::with_context($system)->get_files([
            $component,
            get_string('privacy:entries', 'local_cpdlog'),
            (string) $entry->get('id'),
        ]);
        $this->assertArrayHasKey('certificate.pdf', $files);

        writer::reset();
        $this->export_context_data_for_user($staff->id, $system, 'local_cpdlog');
        $writer = writer::with_context($system);
        $this->assertEmpty($writer->get_data([$component, get_string('privacy:entries', 'local_cpdlog')]));
        $actions = $writer->get_data([$component, get_string('privacy:staffactions', 'local_cpdlog')])->actions;
        $this->assertCount(1, $actions);
        $this->assertEquals($entry->get('id'), $actions[0]->entryid);
        $this->assertObjectNotHasProperty('hours', $actions[0]);
    }

    /**
     * The register of deletions is exported to the member it is about, and as an action to the staff member.
     */
    public function test_export_deletions(): void {
        global $DB;
        $this->resetAfterTest();
        $member = $this->getDataGenerator()->create_user();
        $staff = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_cpdlog_deletion', (object) [
            'userid' => $member->id,
            'requestedby' => $staff->id,
            'status' => 'done',
            'entriesdeleted' => 3,
            'filesdeleted' => 2,
            'timerequested' => time(),
            'timecompleted' => time(),
        ]);
        $system = \context_system::instance();
        $component = get_string('pluginname', 'local_cpdlog');

        $this->assertEquals([$system->id], provider::get_contexts_for_userid($member->id)->get_contextids());
        $this->assertEquals([$system->id], provider::get_contexts_for_userid($staff->id)->get_contextids());
        $userlist = new userlist($system, 'local_cpdlog');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$member->id, $staff->id], $userlist->get_userids());

        $this->export_context_data_for_user($member->id, $system, 'local_cpdlog');
        $deletions = writer::with_context($system)->get_data([$component, get_string('privacy:deletions', 'local_cpdlog')]);
        $this->assertCount(1, $deletions->deletions);
        $this->assertEquals(3, $deletions->deletions[0]->entriesdeleted);

        writer::reset();
        $this->export_context_data_for_user($staff->id, $system, 'local_cpdlog');
        $actions = writer::with_context($system)->get_data([$component, get_string('privacy:staffactions', 'local_cpdlog')]);
        $this->assertCount(1, $actions->actions);
        $this->assertObjectHasProperty('deletionid', $actions->actions[0]);
    }

    /**
     * A cohort choice whose chooser was cleared by a staff data deletion does not list user 0.
     */
    public function test_get_users_in_context_skips_cleared_chooser(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_cpdlog');
        $member = $this->getDataGenerator()->create_user();
        $period = $generator->create_period(['name' => '2026', 'firstday' => '01/01/2026', 'lastday' => '31/12/2026']);
        $DB->insert_record('local_cpdlog_cohortchoice', (object) [
            'userid' => $member->id,
            'periodid' => $period->get('id'),
            'cohortid' => null,
            'chosenby' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $userlist = new userlist(\context_system::instance(), 'local_cpdlog');
        provider::get_users_in_context($userlist);
        $this->assertEquals([$member->id], $userlist->get_userids());
    }

    /**
     * Deletion requests leave CPD records in place: they are retained and deleted manually by staff.
     */
    public function test_delete_retains_records(): void {
        global $DB;
        $this->resetAfterTest();
        [$member, $staff] = $this->create_reviewed_entry();
        $system = \context_system::instance();

        provider::delete_data_for_all_users_in_context($system);
        provider::delete_data_for_user(new approved_contextlist($member, 'local_cpdlog', [$system->id]));
        provider::delete_data_for_users(new approved_userlist($system, 'local_cpdlog', [$member->id, $staff->id]));

        $this->assertSame(1, $DB->count_records(entry::TABLE, ['userid' => $member->id]));
    }

    /**
     * The reminder log is declared, exported, listed and, unlike CPD records, deleted on request.
     */
    public function test_reminders(): void {
        global $DB;
        $this->resetAfterTest();
        [$member, $staff, $entry] = $this->create_reviewed_entry();
        $other = $this->getDataGenerator()->create_user();
        foreach ([$member, $other] as $user) {
            $DB->insert_record('local_cpdlog_reminder', (object) [
                'userid' => $user->id,
                'periodid' => $entry->get('periodid'),
                'daysbefore' => 14,
                'timesent' => time(),
            ]);
        }
        $system = \context_system::instance();
        $component = get_string('pluginname', 'local_cpdlog');

        $this->assertEquals([$system->id], provider::get_contexts_for_userid($other->id)->get_contextids());
        $userlist = new userlist($system, 'local_cpdlog');
        provider::get_users_in_context($userlist);
        $this->assertContainsEquals($other->id, $userlist->get_userids());

        $this->export_context_data_for_user($member->id, $system, 'local_cpdlog');
        $reminders = writer::with_context($system)->get_data([$component, get_string('privacy:reminders', 'local_cpdlog')]);
        $this->assertCount(1, $reminders->reminders);
        $this->assertSame('2026', $reminders->reminders[0]->period);
        $this->assertEquals(14, $reminders->reminders[0]->daysbefore);

        provider::delete_data_for_user(new approved_contextlist($member, 'local_cpdlog', [$system->id]));
        $this->assertSame(0, $DB->count_records('local_cpdlog_reminder', ['userid' => $member->id]));
        $this->assertSame(1, $DB->count_records('local_cpdlog_reminder', ['userid' => $other->id]));
        $this->assertSame(1, $DB->count_records(entry::TABLE, ['userid' => $member->id]));

        provider::delete_data_for_users(new approved_userlist($system, 'local_cpdlog', [$other->id]));
        $this->assertSame(0, $DB->count_records('local_cpdlog_reminder'));
    }

    /**
     * Released and excluded course completions are listed and exported to the member, and as an
     * action to the staff member; they are kept on request.
     */
    public function test_completions(): void {
        global $DB;
        $this->resetAfterTest();
        $member = $this->getDataGenerator()->create_user();
        $staff = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Dermoscopy basics']);
        $completionid = $DB->insert_record('local_cpdlog_completion', (object) [
            'userid' => $member->id,
            'courseid' => $course->id,
            'entryid' => null,
            'status' => 'excluded',
            'timecompleted' => time(),
            'actionedby' => $staff->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $system = \context_system::instance();

        $this->assertEquals([$system->id], provider::get_contexts_for_userid($member->id)->get_contextids());
        $this->assertEquals([$system->id], provider::get_contexts_for_userid($staff->id)->get_contextids());
        $userlist = new userlist($system, 'local_cpdlog');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$member->id, $staff->id], $userlist->get_userids());

        $this->export_context_data_for_user($member->id, $system, 'local_cpdlog');
        $component = get_string('pluginname', 'local_cpdlog');
        $data = writer::with_context($system)->get_data([$component, get_string('privacy:completions', 'local_cpdlog')]);
        $this->assertSame('Dermoscopy basics', $data->completions[0]->course);
        $this->assertSame('excluded', $data->completions[0]->status);

        writer::reset();
        $this->export_context_data_for_user($staff->id, $system, 'local_cpdlog');
        $actions = writer::with_context($system)->get_data([$component, get_string('privacy:staffactions', 'local_cpdlog')]);
        $this->assertEquals($completionid, $actions->actions[0]->completionid);
        $this->assertObjectHasProperty('excluded', $actions->actions[0]);

        provider::delete_data_for_user(new approved_contextlist($member, 'local_cpdlog', [$system->id]));
        $this->assertSame(1, $DB->count_records('local_cpdlog_completion'));
    }
}
