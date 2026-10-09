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
 * Tests for reminding members who are behind on CPD targets before a period closes.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local;

use local_cpdlog\persistent\entry;
use local_cpdlog\persistent\period;
use local_cpdlog\task\send_reminders;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for reminding members who are behind on CPD targets before a period closes.
 */
#[CoversClass(reminder::class)]
#[CoversClass(send_reminders::class)]
#[CoversClass(notifier::class)]
final class reminder_test extends \advanced_testcase
{
    /** @var period The 2026 period, with a 10-hour target for everyone. */
    private period $period;

    /** @var \stdClass A member with 2 approved hours: behind. */
    private \stdClass $behind;

    /** @var \stdClass A member with 10 approved hours: on target. */
    private \stdClass $ontarget;

    /** @var \stdClass A member of a cohort with targets, who logged nothing: behind. */
    private \stdClass $cohortmember;

    /** @var \stdClass A user who logged nothing and is in no cohort with targets: not a member. */
    private \stdClass $outsider;

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
     * Sets up the period, its targets and four users, with reminders at 60 and 14 days.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Australia/Sydney');
        set_config('remindersenabled', 1, 'local_cpdlog');
        set_config('reminderdays', '60,14', 'local_cpdlog');
        $generator = $this->getDataGenerator()->get_plugin_generator('local_cpdlog');
        $this->period = $generator->create_period(['name' => '2026', 'firstday' => '01/01/2026', 'lastday' => '31/12/2026']);
        $generator->create_target(['period' => '2026', 'name' => 'Total CPD', 'requiredhours' => 10]);
        $cohort = $this->getDataGenerator()->create_cohort(['idnumber' => 'fellows']);
        $generator->create_target(['period' => '2026', 'cohort' => 'fellows', 'name' => 'Fellows CPD', 'requiredhours' => 5]);

        $this->behind = $this->getDataGenerator()->create_user(['firstname' => 'Behind']);
        $this->ontarget = $this->getDataGenerator()->create_user(['firstname' => 'Ontarget']);
        $this->cohortmember = $this->getDataGenerator()->create_user(['firstname' => 'Cohort']);
        $this->outsider = $this->getDataGenerator()->create_user(['firstname' => 'Outsider']);
        cohort_add_member($cohort->id, $this->cohortmember->id);
        $entries = [
            [$this->behind, 2, entry::STATUS_APPROVED],
            [$this->behind, 1.5, entry::STATUS_SUBMITTED],
            [$this->ontarget, 10, entry::STATUS_APPROVED],
        ];
        foreach ($entries as [$user, $hours, $status]) {
            $generator->create_entry(['userid' => $user->id, 'period' => '2026', 'hours' => $hours, 'status' => $status]);
        }
    }

    /**
     * Reminder days are read from the setting, ignoring blanks, junk and repeats, largest first.
     */
    public function test_get_thresholds(): void {
        set_config('reminderdays', ' 14, 60,x, 14,,7', 'local_cpdlog');
        $this->assertSame([60, 14, 7], reminder::get_thresholds());
        set_config('reminderdays', '', 'local_cpdlog');
        $this->assertSame([], reminder::get_thresholds());
    }

    /**
     * Data for test_applicable_threshold.
     *
     * @return array
     */
    public static function threshold_provider(): array {
        return [
            'before any reminder' => [61, null],
            'first reminder day' => [60, 60],
            'between reminders' => [30, 60],
            'second reminder day' => [14, 14],
            'last day' => [0, 14],
            'after the period' => [-1, null],
        ];
    }

    /**
     * The smallest reminder point not below the days left applies.
     *
     * @param int $daysleft Days until the period's last day.
     * @param int|null $expected The reminder point that applies.
     */
    #[DataProvider('threshold_provider')]
    public function test_applicable_threshold(int $daysleft, ?int $expected): void {
        $this->assertSame($expected, reminder::applicable_threshold($daysleft, [60, 14]));
    }

    /**
     * Members behind on a target are reminded once, with their unmet targets; others are not.
     */
    public function test_send_due(): void {
        global $DB;
        $sink = $this->redirectMessages();
        $now = self::sydney('2026-12-17 09:00');

        $this->assertSame(2, reminder::send_due($now));

        $messages = $sink->get_messages();
        $this->assertEqualsCanonicalizing(
            [$this->behind->id, $this->cohortmember->id],
            array_column($messages, 'useridto')
        );
        $message = array_values(array_filter($messages, fn($m) => $m->useridto == $this->behind->id))[0];
        $this->assertSame('Your CPD for 2026 is due by 31/12/2026', $message->subject);
        $this->assertSame('periodreminder', $message->eventtype);
        $this->assertStringContainsString(
            'Total CPD: 2.00 of 10.00 hours approved, and 1.50 hours waiting for review',
            $message->fullmessagehtml
        );
        $this->assertStringNotContainsString('Fellows CPD', $message->fullmessagehtml);
        $cohortmessage = array_values(array_filter($messages, fn($m) => $m->useridto == $this->cohortmember->id))[0];
        $this->assertStringContainsString('Fellows CPD: 0.00 of 5.00 hours approved', $cohortmessage->fullmessagehtml);
        $this->assertStringContainsString('periodid=' . $this->period->get('id'), $message->contexturl);
        $this->assertSame(2, $DB->count_records(reminder::TABLE, ['periodid' => $this->period->get('id'), 'daysbefore' => 14]));

        // The same reminder is never sent twice.
        $sink->clear();
        $this->assertSame(0, reminder::send_due($now + DAYSECS));
        $this->assertCount(0, $sink->get_messages());
    }

    /**
     * A site that starts reminding late sends the latest reminder only, not every one at once.
     */
    public function test_late_start_sends_one(): void {
        global $DB;
        $this->redirectMessages();
        $this->assertSame(2, reminder::send_due(self::sydney('2026-12-21 09:00')));
        $this->assertSame(0, $DB->count_records(reminder::TABLE, ['daysbefore' => 60]));
        $this->assertSame(2, $DB->count_records(reminder::TABLE, ['daysbefore' => 14]));
    }

    /**
     * Each reminder point sends its own reminder.
     */
    public function test_each_point_reminds(): void {
        $this->redirectMessages();
        $this->assertSame(0, reminder::send_due(self::sydney('2026-10-31 09:00')));
        $this->assertSame(2, reminder::send_due(self::sydney('2026-11-01 09:00')));
        $this->assertSame(0, reminder::send_due(self::sydney('2026-12-16 09:00')));
        $this->assertSame(2, reminder::send_due(self::sydney('2026-12-17 09:00')));
    }

    /**
     * Nothing is sent while reminders are off, for a closed period, or for suspended or deleted users.
     */
    public function test_not_sent(): void {
        global $DB;
        $sink = $this->redirectMessages();
        $now = self::sydney('2026-12-17 09:00');

        set_config('remindersenabled', 0, 'local_cpdlog');
        $this->assertSame(0, reminder::send_due($now));
        set_config('remindersenabled', 1, 'local_cpdlog');

        $this->period->set('status', period::STATUS_CLOSED);
        $this->period->update();
        $this->assertSame(0, reminder::send_due($now));
        $this->period->set('status', period::STATUS_OPEN);
        $this->period->update();

        $DB->set_field('user', 'suspended', 1, ['id' => $this->behind->id]);
        delete_user($this->cohortmember);
        $this->assertSame(0, reminder::send_due($now));
        $this->assertCount(0, $sink->get_messages());
    }

    /**
     * The daily task sends the reminders due.
     */
    public function test_task(): void {
        global $DB;
        $this->redirectMessages();
        // The task works from today, so put today 14 days before the end of a period.
        $this->period->set('enddate', dates::next_day_start(time() + 14 * DAYSECS));
        $this->period->set('startdate', dates::previous_day_start(time() - 30 * DAYSECS));
        $this->period->update();
        $DB->set_field(entry::TABLE, 'activitydate', time(), []);

        $this->expectOutputRegex('/CPD reminders sent: 2\./');
        (new send_reminders())->execute();
    }
}
