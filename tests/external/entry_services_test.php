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
 * Tests for the web services the Moodle App uses to log CPD entries.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\external;

use core_external\external_api;
use local_cpdlog\local\entry_manager;
use local_cpdlog\persistent\category;
use local_cpdlog\persistent\entry;
use local_cpdlog\persistent\period;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the web services the Moodle App uses to log CPD entries.
 */
#[CoversClass(save_entry::class)]
#[CoversClass(submit_entry::class)]
#[CoversClass(delete_entry::class)]
final class entry_services_test extends \advanced_testcase
{
    /** @var \stdClass The member. */
    private \stdClass $member;

    /** @var \stdClass A course the member is enrolled in. */
    private \stdClass $course;

    /**
     * Sets up an open 2026 period, a closed 2025 period, and a member enrolled in a course.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Australia/Sydney');
        $generator = $this->getDataGenerator()->get_plugin_generator('local_cpdlog');
        $generator->create_period(['name' => '2026', 'firstday' => '01/01/2026', 'lastday' => '31/12/2026']);
        $generator->create_period([
            'name' => '2025',
            'firstday' => '01/01/2025',
            'lastday' => '31/12/2025',
            'status' => period::STATUS_CLOSED,
        ]);
        $this->member = $this->getDataGenerator()->create_user();
        $this->course = $this->getDataGenerator()->create_course(['fullname' => 'Dermoscopy basics']);
        $this->getDataGenerator()->enrol_user($this->member->id, $this->course->id);
        $this->setUser($this->member);
    }

    /**
     * Returns a category's id.
     *
     * @param string $shortname The category short name.
     * @return int
     */
    private static function category(string $shortname): int {
        return (int) category::get_record(['shortname' => $shortname])->get('id');
    }

    /**
     * Saves an entry through the web service, as the app does.
     *
     * @param array $params Parameters to change from a valid new entry.
     * @return array The cleaned result.
     */
    private function save(array $params = []): array {
        $params = array_merge([
            'id' => 0,
            'categoryid' => self::category('EA'),
            'courseid' => (int) $this->course->id,
            'activityname' => '',
            'provider' => '',
            'activitydate' => '2026-03-10',
            'hours' => 1.5,
            'description' => 'Workshop notes',
            'evidence' => save_entry::KEEP_EVIDENCE,
            'submit' => false,
        ], $params);
        $result = save_entry::execute(...array_values($params));
        return external_api::clean_returnvalue(save_entry::execute_returns(), $result);
    }

    /**
     * Puts a file in a new draft area of the current user, as the app's upload does.
     *
     * @param string $filename The file name.
     * @return int The draft area id.
     */
    private function draft_file(string $filename): int {
        global $USER;
        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => $filename,
        ], 'certificate');
        return $draftid;
    }

    /**
     * Returns the names of an entry's evidence files.
     *
     * @param int $entryid The entry.
     * @return string[]
     */
    private static function evidence(int $entryid): array {
        $files = entry_manager::get_evidence_files(new entry($entryid));
        return array_values(array_map(fn($file) => $file->get_filename(), $files));
    }

    /**
     * A new entry is saved as a draft on the day given, in the site timezone, with a plain-text description.
     */
    public function test_save_new_draft(): void {
        $result = $this->save();
        $entry = new entry($result['entryid']);
        $this->assertSame(entry::STATUS_DRAFT, $result['status']);
        $this->assertSame('Draft saved.', $result['message']);
        $this->assertEquals($this->member->id, $entry->get('userid'));
        $this->assertEquals((new \DateTimeImmutable('2026-03-10 00:00', new \DateTimeZone('Australia/Sydney')))
            ->getTimestamp(), $entry->get('activitydate'));
        $this->assertEquals(1.5, $entry->get('hours'));
        $this->assertSame('Workshop notes', $entry->get('description'));
        $this->assertEquals(FORMAT_PLAIN, $entry->get('descriptionformat'));
        $this->assertSame('Dermoscopy basics', $entry->get('coursename'));
    }

    /**
     * Submitting needs evidence where the category requires it, and nothing is saved without it.
     */
    public function test_submit_needs_evidence(): void {
        $ea = category::get_record(['shortname' => 'EA']);
        $ea->set('evidencerequired', 1);
        $ea->update();

        try {
            $this->save(['submit' => true]);
            $this->fail('Expected an exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:evidencerequired', $e->errorcode);
        }
        $this->assertSame(0, entry::count_records());

        $result = $this->save(['submit' => true, 'evidence' => $this->draft_file('certificate.pdf')]);
        $this->assertSame(entry::STATUS_SUBMITTED, $result['status']);
        $this->assertSame('Entry submitted for review.', $result['message']);
        $this->assertSame(['certificate.pdf'], self::evidence($result['entryid']));

        // Invalid details are refused before any evidence check, with the rules' own message.
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error:entryhours', 'local_cpdlog'));
        $this->save(['submit' => true, 'hours' => 0]);
    }

    /**
     * Editing keeps the description and its formatting, and the evidence, unless the app sends new ones.
     */
    public function test_edit_keeps_unchanged(): void {
        $id = $this->save(['evidence' => $this->draft_file('one.pdf')])['entryid'];
        $entry = new entry($id);
        $entry->set('description', '<p>Formatted <strong>notes</strong></p>');
        $entry->set('descriptionformat', FORMAT_HTML);
        $entry->update();

        $this->save(['id' => $id, 'hours' => 2, 'description' => null]);
        $entry = new entry($id);
        $this->assertEquals(2, $entry->get('hours'));
        $this->assertSame('<p>Formatted <strong>notes</strong></p>', $entry->get('description'));
        $this->assertEquals(FORMAT_HTML, $entry->get('descriptionformat'));
        $this->assertSame(['one.pdf'], self::evidence($id));

        $this->save(['id' => $id, 'description' => 'Plain notes', 'evidence' => $this->draft_file('two.pdf')]);
        $entry = new entry($id);
        $this->assertSame('Plain notes', $entry->get('description'));
        $this->assertEquals(FORMAT_PLAIN, $entry->get('descriptionformat'));
        $this->assertSame(['two.pdf'], self::evidence($id));

        $this->save(['id' => $id, 'evidence' => 0]);
        $this->assertSame([], self::evidence($id));
        $this->assertSame(1, entry::count_records());
    }

    /**
     * An activity outside Moodle is saved with its name and provider in a category that accepts it,
     * and needs evidence to be submitted.
     */
    public function test_external_activity(): void {
        $ea = category::get_record(['shortname' => 'EA']);
        $ea->set('allowexternal', 1);
        $ea->update();
        $params = ['courseid' => entry::EXTERNAL_COURSE, 'activityname' => 'Skin conference', 'provider' => 'SCCA'];

        $entry = new entry($this->save($params)['entryid']);
        $this->assertTrue($entry->is_external());
        $this->assertSame('Skin conference', $entry->get('activityname'));
        $this->assertSame('SCCA', $entry->get('provider'));

        try {
            $this->save($params + ['submit' => true]);
            $this->fail('Expected an exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:evidenceexternal', $e->errorcode);
        }
    }

    /**
     * Only the member's own draft or rejected entries in open periods can be changed, submitted or deleted.
     */
    public function test_locked_entries(): void {
        $id = $this->save()['entryid'];
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($other);
        foreach (
            [
                fn() => $this->save(['id' => $id]),
                fn() => submit_entry::execute($id),
                fn() => delete_entry::execute($id),
                fn() => submit_entry::execute($id + 100),
                fn() => $this->save(['id' => $id + 100]),
            ] as $call
        ) {
            try {
                $call();
                $this->fail('Expected an exception');
            } catch (\moodle_exception $e) {
                $this->assertSame('error:entrylocked', $e->errorcode);
            }
        }

        $this->setUser($this->member);
        submit_entry::execute($id);
        $this->expectExceptionMessage(get_string('error:entrylocked', 'local_cpdlog'));
        $this->save(['id' => $id]);
    }

    /**
     * Drafts are submitted and deleted through their own services.
     */
    public function test_submit_and_delete(): void {
        $first = $this->save()['entryid'];
        $second = $this->save(['activitydate' => '2026-03-11'])['entryid'];

        $result = external_api::clean_returnvalue(submit_entry::execute_returns(), submit_entry::execute($first));
        $this->assertSame('Entry submitted for review.', $result['message']);
        $this->assertSame(entry::STATUS_SUBMITTED, (new entry($first))->get('status'));

        $result = external_api::clean_returnvalue(delete_entry::execute_returns(), delete_entry::execute($second));
        $this->assertSame('Draft entry deleted.', $result['message']);
        $this->assertFalse(entry::record_exists($second));

        $this->expectException(\moodle_exception::class);
        delete_entry::execute($first);
    }

    /**
     * Dates must be real days as YYYY-MM-DD, and dates in closed periods are refused.
     */
    public function test_dates(): void {
        foreach (['2026-02-30', '10/03/2026', '2026-3-10'] as $day) {
            try {
                $this->save(['activitydate' => $day]);
                $this->fail('Expected an exception for ' . $day);
            } catch (\invalid_parameter_exception $e) {
                $this->assertSame('invalidparameter', $e->errorcode);
            }
        }
        $this->expectExceptionMessage(get_string('error:entryperiodclosed', 'local_cpdlog', '2025'));
        $this->save(['activitydate' => '2025-06-01']);
    }

    /**
     * A likely duplicate is saved with a warning.
     */
    public function test_duplicate_warning(): void {
        $this->save();
        $this->assertStringContainsString(get_string('duplicatewarning', 'local_cpdlog'), $this->save()['message']);
    }

    /**
     * Members without the capability to log CPD cannot use the services.
     */
    public function test_capability(): void {
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/cpdlog:submit', CAP_PROHIBIT, $roleid, \context_system::instance());
        role_assign($roleid, $this->member->id, \context_system::instance());
        $this->expectException(\required_capability_exception::class);
        $this->save();
    }
}
