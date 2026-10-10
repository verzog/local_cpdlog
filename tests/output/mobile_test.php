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
 * Tests for the CPD logbook screens in the Moodle App.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\output;

use local_cpdlog\local\entry_manager;
use local_cpdlog\persistent\category;
use local_cpdlog\persistent\entry;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the CPD logbook screens in the Moodle App.
 */
#[CoversClass(mobile::class)]
final class mobile_test extends \advanced_testcase
{
    /** @var \stdClass The member. */
    private \stdClass $member;

    /** @var \stdClass A course the member is enrolled in. */
    private \stdClass $course;

    /**
     * Sets up 2025 and 2026 periods, a target, and a member enrolled in a course.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Australia/Sydney');
        $generator = $this->getDataGenerator()->get_plugin_generator('local_cpdlog');
        $generator->create_period(['name' => '2025', 'firstday' => '01/01/2025', 'lastday' => '31/12/2025']);
        $generator->create_period(['name' => '2026', 'firstday' => '01/01/2026', 'lastday' => '31/12/2026']);
        $generator->create_target(['period' => '2026', 'name' => 'Total CPD', 'requiredhours' => 10]);
        $this->member = $this->getDataGenerator()->create_user();
        $this->course = $this->getDataGenerator()->create_course(['fullname' => 'Dermoscopy basics']);
        $this->getDataGenerator()->enrol_user($this->member->id, $this->course->id);
        $this->setUser($this->member);
    }

    /**
     * Saves a draft entry for the member.
     *
     * @param array $overrides Details to change.
     * @return entry
     */
    private function draft(array $overrides = []): entry {
        return entry_manager::save_draft((int) $this->member->id, (object) ($overrides + [
            'categoryid' => category::get_record(['shortname' => 'EA'])->get('id'),
            'courseid' => $this->course->id,
            'activitydate' => (new \DateTimeImmutable('2026-03-10', new \DateTimeZone('Australia/Sydney')))->getTimestamp(),
            'hours' => 2,
            'description' => '<p>Workshop <strong>notes</strong></p>',
            'descriptionformat' => FORMAT_HTML,
        ]));
    }

    /**
     * Decodes a screen's otherdata, as the app does.
     *
     * @param array $result The screen.
     * @return array
     */
    private static function otherdata(array $result): array {
        return array_map(fn($value) => json_decode($value, true), $result['otherdata']);
    }

    /**
     * The main menu item shows only to users who have a logbook.
     */
    public function test_init(): void {
        $this->assertFalse(mobile::mobile_init([])['disabled']);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/cpdlog:viewown', CAP_PROHIBIT, $roleid, \context_system::instance());
        role_assign($roleid, $this->member->id, \context_system::instance());
        $this->assertTrue(mobile::mobile_init([])['disabled']);
        $this->expectException(\required_capability_exception::class);
        mobile::mobile_logbook([]);
    }

    /**
     * The logbook lists the member's entries with the actions open to them, and progress in the
     * current period or the one chosen.
     */
    public function test_logbook(): void {
        $ea = category::get_record(['shortname' => 'EA']);
        $draft = $this->draft();
        $submitted = $this->draft(['activitydate' => $draft->get('activitydate') + DAYSECS]);
        entry_manager::submit($submitted, (int) $this->member->id);

        $result = mobile::mobile_logbook([]);
        $data = self::otherdata($result);
        $this->assertSame('Progress for 2026', $data['progress']['heading']);
        $this->assertSame('Total CPD', $data['progress']['targets'][0]['name']);
        $this->assertSame(['2026', '2025'], array_column($data['periods'], 'name'));
        $byid = array_column($data['entries'], null, 'id');
        $this->assertSame('10/03/2026', $byid[$draft->get('id')]['date']);
        $this->assertSame('Dermoscopy basics', $byid[$draft->get('id')]['activity']);
        $this->assertSame('Draft', $byid[$draft->get('id')]['status']);
        $this->assertTrue($byid[$draft->get('id')]['cansubmit']);
        $this->assertTrue($byid[$draft->get('id')]['candelete']);
        $this->assertFalse($byid[$submitted->get('id')]['canedit']);
        $this->assertSame('warning', $byid[$submitted->get('id')]['colour']);
        $this->assertStringContainsString('core-site-plugins-call-ws', $result['templates'][0]['html']);
        $this->assertStringContainsString('{{ entry.activity }}', $result['templates'][0]['html']);
        $this->assertStringContainsString('local_cpdlog_entry_saved', $result['javascript']);

        // A draft that needs evidence cannot be submitted until it has some.
        $ea->set('evidencerequired', 1);
        $ea->update();
        $data = self::otherdata(mobile::mobile_logbook(['periodid' => $data['periods'][1]['id']]));
        $this->assertSame('Progress for 2025', $data['progress']['heading']);
        $byid = array_column($data['entries'], null, 'id');
        $this->assertFalse($byid[$draft->get('id')]['cansubmit']);
        $this->assertTrue($byid[$draft->get('id')]['evidenceneeded']);
    }

    /**
     * What members type never reaches the template, which the app compiles as Angular code.
     */
    public function test_user_text_stays_out_of_templates(): void {
        $ea = category::get_record(['shortname' => 'EA']);
        $ea->set('allowexternal', 1);
        $ea->update();
        $entry = $this->draft(['courseid' => entry::EXTERNAL_COURSE, 'activityname' => '{{ constructor }}', 'provider' => 'X']);

        $logbook = mobile::mobile_logbook([]);
        $form = mobile::mobile_entry_form(['id' => $entry->get('id')]);
        foreach ([$logbook, $form] as $result) {
            $this->assertStringNotContainsString('constructor', $result['templates'][0]['html']);
        }
        $this->assertStringContainsString('{{ constructor }}', self::otherdata($logbook)['entries'][0]['activity']);
        $this->assertSame('{{ constructor }}', self::otherdata($form)['entry']['activityname']);
    }

    /**
     * The form starts empty for a new entry, and holds an entry's details, plain-text description and
     * evidence for editing.
     */
    public function test_entry_form(): void {
        $data = self::otherdata(mobile::mobile_entry_form([]));
        $this->assertSame(0, $data['entry']['id']);
        $this->assertNull($data['entry']['categoryid']);
        $this->assertSame([], $data['files']);
        $this->assertSame([['id' => (int) $this->course->id, 'name' => 'Dermoscopy basics']], $data['courses']);
        $this->assertContains('Educational activities', array_column($data['categories'], 'name'));
        $this->assertSame('Saving', $data['strings']['saving']);

        $entry = $this->draft();
        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($this->member->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => 'certificate.pdf',
        ], 'certificate');
        entry_manager::save_evidence($entry, (int) $this->member->id, $draftid);

        $result = mobile::mobile_entry_form(['id' => $entry->get('id')]);
        $data = self::otherdata($result);
        $this->assertSame('2026-03-10', $data['entry']['activitydate']);
        $this->assertEquals($this->course->id, $data['entry']['courseid']);
        $this->assertEquals(2, $data['entry']['hours']);
        $this->assertSame('Workshop NOTES', $data['entry']['description']);
        $this->assertSame('certificate.pdf', $data['files'][0]['filename']);
        $this->assertStringContainsString('/webservice/pluginfile.php/', $data['files'][0]['fileurl']);
        $this->assertStringContainsString('saveEntry', $result['javascript']);
        $this->assertStringContainsString('acceptedTypes=".pdf,.doc,.docx,.jpg,.jpeg,.png"', $result['templates'][0]['html']);

        // Only the member's own editable entries can be opened.
        entry_manager::submit($entry, (int) $this->member->id);
        $this->expectException(\moodle_exception::class);
        mobile::mobile_entry_form(['id' => $entry->get('id')]);
    }
}
