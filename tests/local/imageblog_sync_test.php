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
 * Tests for copying image blog CPD awards into the logbook.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local;

use local_cpdlog\persistent\category;
use local_cpdlog\persistent\entry;
use local_cpdlog\task\sync_imageblog;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for copying image blog CPD awards into the logbook.
 *
 * They need local_imageblog installed, and are skipped without it.
 */
#[CoversClass(imageblog_sync::class)]
#[CoversClass(sync_imageblog::class)]
final class imageblog_sync_test extends \advanced_testcase
{
    /** @var \stdClass A member earning CPD in the image blog. */
    private \stdClass $member;

    /** @var int A clinical case post in the image blog. */
    private int $postid;

    /** @var int 10:00 on 15/03/2026 in Sydney, inside the 2026 period. */
    private int $awarded;

    /**
     * Sets up the 2026 period, a member and a case, with copying switched on.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        if (!imageblog_sync::is_installed()) {
            $this->markTestSkipped('local_imageblog is not installed.');
        }
        $this->resetAfterTest();
        $this->setTimezone('Australia/Sydney');
        $generator = $this->getDataGenerator()->get_plugin_generator('local_cpdlog');
        $generator->create_period(['name' => '2026', 'firstday' => '01/01/2026', 'lastday' => '31/12/2026']);
        set_config('imageblogenabled', 1, 'local_cpdlog');
        $this->member = $this->getDataGenerator()->create_user();
        $author = $this->getDataGenerator()->create_user();
        $this->postid = (int) $DB->insert_record(imageblog_sync::POST_TABLE, (object) [
            'authorid' => $author->id,
            'title' => 'Pigmented lesion on the back',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $this->awarded = (new \DateTime('2026-03-15 10:00', new \DateTimeZone('Australia/Sydney')))->getTimestamp();
    }

    /**
     * Records an award in the image blog's table, as the image blog does.
     *
     * @param string $reason participation, bestanswer or view.
     * @param float $hours The hours.
     * @param int|null $time When it was awarded; default 15/03/2026.
     * @param int|null $userid The member; default the test member.
     * @return int The award id.
     */
    private function award(string $reason, float $hours, ?int $time = null, ?int $userid = null): int {
        global $DB;
        return (int) $DB->insert_record(imageblog_sync::AWARD_TABLE, (object) [
            'postid' => $this->postid,
            'userid' => $userid ?? $this->member->id,
            'hours' => $hours,
            'reason' => $reason,
            'timeawarded' => $time ?? $this->awarded,
        ]);
    }

    /**
     * Returns the member's entry for an award reason.
     *
     * @param string $reason The award reason.
     * @return entry|false
     */
    private function entry_for(string $reason) {
        return entry::get_record([
            'source' => entry::SOURCE_IMAGEBLOG,
            'externalref' => $this->postid . ':' . $this->member->id . ':' . $reason,
        ]);
    }

    /**
     * Each award becomes an approved, read-only entry with no course, counted in the member's progress.
     */
    public function test_sync_creates_approved_entries(): void {
        $this->award('participation', 1.5);
        $this->award('bestanswer', 0.75);
        $this->award('view', 0.25);

        $result = imageblog_sync::sync();

        $this->assertEquals((object) ['created' => 3, 'updated' => 0, 'reversed' => 0, 'skipped' => 0], $result);
        $entry = $this->entry_for('participation');
        $this->assertSame(entry::STATUS_APPROVED, $entry->get('status'));
        $this->assertNull($entry->get('courseid'));
        $this->assertSame('Image blog', $entry->get('coursename'));
        $this->assertEquals(1.5, $entry->get('hours'));
        $this->assertEquals($this->awarded, $entry->get('activitydate'));
        $this->assertSame('EA', (new category($entry->get('categoryid')))->get('shortname'));
        $this->assertStringContainsString('Pigmented lesion on the back', $entry->get('description'));
        $this->assertStringContainsString('submitted a diagnosis', $entry->get('description'));
        $this->assertFalse($entry->is_moodle_owned());
        $this->assertFalse(entry_manager::can_edit($entry, (int) $this->member->id));

        $hours = progress::get_hours_by_category((int) $this->member->id, (int) $entry->get('periodid'));
        $this->assertEquals(2.5, array_sum($hours['approved']));
    }

    /**
     * Running again changes nothing; a change of hours in the image blog is followed.
     */
    public function test_sync_updates_hours(): void {
        global $DB;
        $awardid = $this->award('participation', 1.5);
        imageblog_sync::sync();
        $this->assertEquals((object) ['created' => 0, 'updated' => 0, 'reversed' => 0, 'skipped' => 0], imageblog_sync::sync());

        $DB->set_field(imageblog_sync::AWARD_TABLE, 'hours', 3, ['id' => $awardid]);
        $result = imageblog_sync::sync();

        $this->assertSame(1, $result->updated);
        $this->assertEquals(3, $this->entry_for('participation')->get('hours'));
        $this->assertSame(1, entry::count_records(['userid' => $this->member->id]));
    }

    /**
     * A withdrawn award reverses its entry, which stops counting; if the award returns, so does the entry.
     */
    public function test_withdrawn_award_reversed_then_restored(): void {
        global $DB;
        $awardid = $this->award('bestanswer', 0.75);
        imageblog_sync::sync();

        $DB->delete_records(imageblog_sync::AWARD_TABLE, ['id' => $awardid]);
        $this->assertSame(1, imageblog_sync::sync()->reversed);
        $entry = $this->entry_for('bestanswer');
        $this->assertSame(entry::STATUS_REVERSED, $entry->get('status'));
        $this->assertSame('The award was withdrawn in the image blog.', $entry->get('reversalreason'));
        $this->assertSame(0, imageblog_sync::sync()->reversed);

        $this->award('bestanswer', 0.75);
        $this->assertSame(1, imageblog_sync::sync()->updated);
        $entry = $this->entry_for('bestanswer');
        $this->assertSame(entry::STATUS_APPROVED, $entry->get('status'));
        $this->assertNull($entry->get('reversalreason'));
    }

    /**
     * An award outside every period waits until a period covers it, then is copied.
     */
    public function test_award_outside_periods_waits(): void {
        $this->award('view', 0.25, (new \DateTime('2025-06-01 10:00', new \DateTimeZone('Australia/Sydney')))->getTimestamp());
        $this->assertSame(1, imageblog_sync::sync()->skipped);
        $this->assertFalse($this->entry_for('view'));

        $this->getDataGenerator()->get_plugin_generator('local_cpdlog')
            ->create_period(['name' => '2025', 'firstday' => '01/01/2025', 'lastday' => '31/12/2025']);
        $this->assertSame(1, imageblog_sync::sync()->created);
    }

    /**
     * New entries go in the chosen category, falling back to Educational activities if it is gone.
     */
    public function test_category_setting(): void {
        set_config('imageblogcategory', 'RP', 'local_cpdlog');
        $this->award('participation', 1);
        imageblog_sync::sync();
        $this->assertSame('RP', (new category($this->entry_for('participation')->get('categoryid')))->get('shortname'));

        set_config('imageblogcategory', 'GONE', 'local_cpdlog');
        $this->assertSame('EA', imageblog_sync::get_category()->get('shortname'));
        $this->assertArrayHasKey('EA', imageblog_sync::get_category_choices());
    }

    /**
     * Copying is on until switched off, including before the setting has ever been saved.
     */
    public function test_is_enabled(): void {
        unset_config('imageblogenabled', 'local_cpdlog');
        $this->assertTrue(imageblog_sync::is_enabled());
        set_config('imageblogenabled', 0, 'local_cpdlog');
        $this->assertFalse(imageblog_sync::is_enabled());
        set_config('imageblogenabled', 1, 'local_cpdlog');
        $this->assertTrue(imageblog_sync::is_enabled());
    }

    /**
     * Nothing is copied while copying is switched off.
     */
    public function test_switched_off(): void {
        set_config('imageblogenabled', 0, 'local_cpdlog');
        $this->award('participation', 1);

        $this->assertSame(0, imageblog_sync::sync()->created);
        $this->assertSame(0, entry::count_records(['source' => entry::SOURCE_IMAGEBLOG]));
    }

    /**
     * Awards made before a member's CPD data was deleted are not copied back; later awards are.
     */
    public function test_respects_data_deletion(): void {
        // Deletion works on real timestamps, so this test needs a period covering now.
        if (!entry_manager::find_period(time())) {
            $this->getDataGenerator()->get_plugin_generator('local_cpdlog')->create_period([
                'name' => 'Now',
                'firstday' => userdate(time() - 3 * DAYSECS, '%d/%m/%Y'),
                'lastday' => userdate(time() + 3 * DAYSECS, '%d/%m/%Y'),
            ]);
        }
        $this->award('participation', 1, time() - HOURSECS);
        imageblog_sync::sync();
        $this->assertSame(1, entry::count_records(['userid' => $this->member->id]));

        set_config('enabledeletion', 1, 'local_cpdlog');
        data_deleter::queue((int) $this->member->id, (int) get_admin()->id);
        $this->award('view', 0.25, time());
        $this->assertSame(0, imageblog_sync::sync()->created);

        $this->runAdhocTasks(\local_cpdlog\task\delete_member_data::class);
        $this->assertSame(0, entry::count_records(['userid' => $this->member->id]));
        $this->assertSame(0, imageblog_sync::sync()->created);
        $this->assertSame(0, entry::count_records(['userid' => $this->member->id]));

        $this->award('bestanswer', 0.5, time() + 60);
        $this->assertSame(1, imageblog_sync::sync()->created);
        $this->assertNotFalse($this->entry_for('bestanswer'));
    }

    /**
     * The scheduled task runs the copy.
     */
    public function test_task(): void {
        $this->award('participation', 1);
        $this->expectOutputRegex('/1 created/');
        (new sync_imageblog())->execute();
        $this->assertNotFalse($this->entry_for('participation'));
    }
}
