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
 * Tests for the CPD logbook privacy provider.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
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
     * Every table holding personal data, and the evidence files, are declared.
     */
    public function test_get_metadata_declares_tables(): void {
        $collection = provider::get_metadata(new collection('local_cpdlog'));
        $names = array_map(fn($item) => $item->get_name(), $collection->get_collection());

        $this->assertEqualsCanonicalizing([
            'core_files',
            'local_cpdlog_category',
            'local_cpdlog_cohortchoice',
            'local_cpdlog_entry',
            'local_cpdlog_period',
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
}
