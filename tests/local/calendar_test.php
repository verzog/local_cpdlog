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
 * Tests for keeping reporting period dates in the Moodle calendar.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local;

use local_cpdlog\persistent\period;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for keeping reporting period dates in the Moodle calendar.
 */
#[CoversClass(calendar::class)]
#[CoversClass(period::class)]
final class calendar_test extends \advanced_testcase
{
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
     * Sets up the site timezone.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Australia/Sydney');
    }

    /**
     * A new period puts site events on its first and last days, owned by the plugin.
     */
    public function test_new_period_adds_events(): void {
        // As an administrator, who could edit any ordinary site event.
        $this->setAdminUser();
        $period = $this->getDataGenerator()->get_plugin_generator('local_cpdlog')
            ->create_period(['name' => '2026', 'firstday' => '01/01/2026', 'lastday' => '31/12/2026']);

        $opens = calendar::get_event((int) $period->get('id'), calendar::OPENS);
        $closes = calendar::get_event((int) $period->get('id'), calendar::CLOSES);
        $this->assertSame('CPD reporting period 2026 opens', $opens->name);
        $this->assertSame('CPD reporting period 2026 closes', $closes->name);
        $this->assertEquals(self::sydney('2026-01-01 00:00'), $opens->timestart);
        $this->assertEquals(self::sydney('2026-12-31 00:00'), $closes->timestart);
        foreach ([$opens, $closes] as $event) {
            $this->assertEquals(SITEID, $event->courseid);
            $this->assertSame('site', $event->eventtype);
            $this->assertSame('local_cpdlog', $event->component);
            // Nobody can change or delete the events by hand in the calendar.
            $this->assertFalse(calendar_edit_event_allowed($event, true));
            $this->assertFalse(calendar_delete_event_allowed($event));
        }
    }

    /**
     * Changing a period moves and renames its events; deleting it removes them.
     */
    public function test_period_changes_follow(): void {
        global $DB;
        $period = $this->getDataGenerator()->get_plugin_generator('local_cpdlog')
            ->create_period(['name' => '2026', 'firstday' => '01/01/2026', 'lastday' => '31/12/2026']);
        $periodid = (int) $period->get('id');

        $period->set('name', 'Year 2026');
        $period->set('enddate', self::sydney('2027-01-31 00:00'));
        $period->update();
        $closes = calendar::get_event($periodid, calendar::CLOSES);
        $this->assertSame('CPD reporting period Year 2026 closes', $closes->name);
        $this->assertEquals(self::sydney('2027-01-30 00:00'), $closes->timestart);
        $this->assertSame(2, $DB->count_records('event', ['component' => 'local_cpdlog', 'instance' => $periodid]));

        $period->delete();
        $this->assertSame(0, $DB->count_records('event', ['component' => 'local_cpdlog']));
    }

    /**
     * Syncing every period restores missing events without duplicating existing ones.
     */
    public function test_sync_all(): void {
        global $DB;
        $generator = $this->getDataGenerator()->get_plugin_generator('local_cpdlog');
        $p2025 = $generator->create_period(['name' => '2025', 'firstday' => '01/01/2025', 'lastday' => '31/12/2025']);
        $generator->create_period(['name' => '2026', 'firstday' => '01/01/2026', 'lastday' => '31/12/2026']);
        $uuid = 'local_cpdlog-period-' . $p2025->get('id') . '-opens';
        $DB->delete_records('event', ['component' => 'local_cpdlog', 'uuid' => $uuid]);

        calendar::sync_all();
        calendar::sync_all();

        $this->assertSame(4, $DB->count_records('event', ['component' => 'local_cpdlog']));
    }

    /**
     * The events show to users who can view a logbook, and not to those who cannot.
     */
    public function test_visibility(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/cpdlog/lib.php');
        $period = $this->getDataGenerator()->get_plugin_generator('local_cpdlog')
            ->create_period(['name' => '2026', 'firstday' => '01/01/2026', 'lastday' => '31/12/2026']);
        $event = calendar::get_event((int) $period->get('id'), calendar::CLOSES);
        $member = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/cpdlog:viewown', CAP_PROHIBIT, $roleid, \context_system::instance());
        role_assign($roleid, $other->id, \context_system::instance());

        $this->assertTrue(local_cpdlog_core_calendar_is_event_visible($event, (int) $member->id));
        $this->assertFalse(local_cpdlog_core_calendar_is_event_visible($event, (int) $other->id));
    }
}
