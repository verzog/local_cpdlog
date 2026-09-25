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
 * Tests for staff approving and rejecting CPD entries.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\local;

use local_cpdlog\event\entry_approved;
use local_cpdlog\event\entry_rejected;
use local_cpdlog\persistent\category;
use local_cpdlog\persistent\entry;
use local_cpdlog\persistent\period;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for staff approving and rejecting CPD entries, and the notifications they send.
 */
#[CoversClass(review_manager::class)]
#[CoversClass(notifier::class)]
final class review_manager_test extends \advanced_testcase
{
    /** @var \stdClass The member. */
    private \stdClass $member;

    /** @var \stdClass An approver. */
    private \stdClass $approver;

    /** @var \stdClass A second approver. */
    private \stdClass $approver2;

    /** @var \stdClass A course the member is enrolled in. */
    private \stdClass $course;

    /** @var period The open 2026 period. */
    private period $period;

    /** @var period The closed 2025 period. */
    private period $closed;

    /**
     * Sets up open and closed periods, a member, two approvers and a course.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Australia/Sydney');

        $generator = $this->getDataGenerator()->get_plugin_generator('local_cpdlog');
        $this->period = $generator->create_period(['name' => '2026', 'firstday' => '01/01/2026', 'lastday' => '31/12/2026']);
        $this->closed = $generator->create_period([
            'name' => '2025',
            'firstday' => '01/01/2025',
            'lastday' => '31/12/2025',
            'status' => period::STATUS_CLOSED,
        ]);

        $this->member = $this->getDataGenerator()->create_user(['firstname' => 'Mary', 'lastname' => 'Member']);
        $this->course = $this->getDataGenerator()->create_course(['fullname' => 'Dermoscopy basics']);
        $this->getDataGenerator()->enrol_user($this->member->id, $this->course->id);

        $role = $this->getDataGenerator()->create_role();
        assign_capability('local/cpdlog:approve', CAP_ALLOW, $role, \context_system::instance());
        $this->approver = $this->getDataGenerator()->create_user();
        $this->approver2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign($role, $this->approver->id);
        $this->getDataGenerator()->role_assign($role, $this->approver2->id);
    }

    /**
     * Creates a submitted entry for the member, or another user, in the open period.
     *
     * @param array $overrides Fields to change.
     * @return entry
     */
    private function submitted(array $overrides = []): entry {
        return $this->getDataGenerator()->get_plugin_generator('local_cpdlog')->create_entry($overrides + [
            'userid' => $this->member->id,
            'periodid' => $this->period->get('id'),
            'courseid' => $this->course->id,
            'coursename' => $this->course->fullname,
            'day' => '10/03/2026',
            'hours' => 2.5,
            'status' => entry::STATUS_SUBMITTED,
            'timesubmitted' => time(),
        ]);
    }

    /**
     * Only submitted Moodle entries in open periods can be reviewed, and never by their owner.
     */
    public function test_can_review(): void {
        $reviewerid = (int) $this->approver->id;

        $this->assertTrue(review_manager::can_review($this->submitted(), $reviewerid));
        $this->assertFalse(review_manager::can_review($this->submitted(['userid' => $reviewerid]), $reviewerid));
        $this->assertFalse(review_manager::can_review($this->submitted(['status' => entry::STATUS_DRAFT]), $reviewerid));
        $this->assertFalse(review_manager::can_review($this->submitted(['status' => entry::STATUS_APPROVED]), $reviewerid));
        $this->assertFalse(review_manager::can_review($this->submitted(['source' => entry::SOURCE_IMIS]), $reviewerid));
        $closed = $this->submitted(['periodid' => $this->closed->get('id'), 'day' => '10/03/2025']);
        $this->assertFalse(review_manager::can_review($closed, $reviewerid));
    }

    /**
     * Approving records the reviewer and time, logs it, and tells the member.
     */
    public function test_approve(): void {
        $entry = $this->submitted();
        $this->setUser($this->approver);
        $events = $this->redirectEvents();
        $messages = $this->redirectMessages();

        review_manager::approve($entry, (int) $this->approver->id);

        $saved = new entry($entry->get('id'));
        $this->assertSame(entry::STATUS_APPROVED, $saved->get('status'));
        $this->assertEquals($this->approver->id, $saved->get('reviewedby'));
        $this->assertNotNull($saved->get('timereviewed'));
        $approved = array_filter($events->get_events(), fn($event) => $event instanceof entry_approved);
        $this->assertCount(1, $approved);

        $sent = $messages->get_messages();
        $this->assertCount(1, $sent);
        $this->assertEquals($this->member->id, $sent[0]->useridto);
        $this->assertSame('entryoutcome', $sent[0]->eventtype);
        $this->assertSame('CPD activity approved', $sent[0]->subject);
        $this->assertStringContainsString('10/03/2026', $sent[0]->fullmessagehtml);
        $this->assertStringContainsString('Dermoscopy basics', $sent[0]->fullmessagehtml);
        $this->assertStringContainsString('2.50 hours', $sent[0]->fullmessagehtml);

        // A reviewed entry cannot be reviewed again.
        $this->expectException(\moodle_exception::class);
        review_manager::approve($saved, (int) $this->approver2->id);
    }

    /**
     * Approvers cannot approve their own entries.
     */
    public function test_approve_own_entry_refused(): void {
        $entry = $this->submitted(['userid' => $this->approver->id]);

        try {
            review_manager::approve($entry, (int) $this->approver->id);
            $this->fail('An approver approved their own entry.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:entrynotreviewable', $e->errorcode);
        }
        $this->assertSame(entry::STATUS_SUBMITTED, (new entry($entry->get('id')))->get('status'));

        review_manager::approve($entry, (int) $this->approver2->id);
        $this->assertSame(entry::STATUS_APPROVED, (new entry($entry->get('id')))->get('status'));
    }

    /**
     * When two approvers act on the same entry at once, only the first review counts.
     */
    public function test_concurrent_reviews(): void {
        $entry = $this->submitted();
        // Each approver loaded the entry while it was still submitted.
        $first = new entry($entry->get('id'));
        $second = new entry($entry->get('id'));
        $messages = $this->redirectMessages();

        review_manager::approve($first, (int) $this->approver->id);
        try {
            review_manager::reject($second, (int) $this->approver2->id, 'Too late.');
            $this->fail('A second review replaced the first.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:entrynotreviewable', $e->errorcode);
        }

        $saved = new entry($entry->get('id'));
        $this->assertSame(entry::STATUS_APPROVED, $saved->get('status'));
        $this->assertEquals($this->approver->id, $saved->get('reviewedby'));
        $this->assertNull($saved->get('rejectionreason'));
        $this->assertCount(1, $messages->get_messages());
    }

    /**
     * Rejecting needs a reason, which is stored, logged and sent to the member.
     */
    public function test_reject(): void {
        $entry = $this->submitted();
        $this->setUser($this->approver);

        try {
            review_manager::reject($entry, (int) $this->approver->id, "  \n ");
            $this->fail('An entry was rejected without a reason.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:reasonrequired', $e->errorcode);
        }
        $this->assertSame(entry::STATUS_SUBMITTED, (new entry($entry->get('id')))->get('status'));

        $events = $this->redirectEvents();
        $messages = $this->redirectMessages();
        review_manager::reject($entry, (int) $this->approver->id, " Attach the <certificate>. ");

        $saved = new entry($entry->get('id'));
        $this->assertSame(entry::STATUS_REJECTED, $saved->get('status'));
        $this->assertSame('Attach the <certificate>.', $saved->get('rejectionreason'));
        $this->assertEquals($this->approver->id, $saved->get('reviewedby'));
        $rejected = array_filter($events->get_events(), fn($event) => $event instanceof entry_rejected);
        $this->assertCount(1, $rejected);

        $sent = $messages->get_messages();
        $this->assertCount(1, $sent);
        $this->assertEquals($this->member->id, $sent[0]->useridto);
        $this->assertSame('CPD activity not approved', $sent[0]->subject);
        // The reason is escaped in the HTML message.
        $this->assertStringContainsString('Attach the &lt;certificate&gt;.', $sent[0]->fullmessagehtml);
    }

    /**
     * A rejected entry goes back to the member, who can edit and resubmit it.
     */
    public function test_rejected_entry_can_be_resubmitted(): void {
        $entry = $this->submitted();
        review_manager::reject($entry, (int) $this->approver->id, 'Wrong course.');
        $entry = new entry($entry->get('id'));
        $memberid = (int) $this->member->id;
        $this->assertTrue(entry_manager::can_edit($entry, $memberid));

        $details = $entry->to_record();
        $details->hours = 2;
        $entry = entry_manager::save_draft($memberid, $details, $entry);
        entry_manager::submit($entry, $memberid);

        $this->assertTrue(review_manager::can_review(new entry($entry->get('id')), (int) $this->approver->id));
    }

    /**
     * Bulk approval approves what it can and skips the rest, up to the limit.
     */
    public function test_approve_many(): void {
        $first = $this->submitted();
        $second = $this->submitted(['day' => '11/03/2026']);
        $own = $this->submitted(['userid' => $this->approver->id]);
        $draft = $this->submitted(['status' => entry::STATUS_DRAFT]);
        $ids = [$first->get('id'), $second->get('id'), $second->get('id'), $own->get('id'), $draft->get('id'), 999999];

        $this->assertSame(2, review_manager::approve_many($ids, (int) $this->approver->id));
        $this->assertSame(entry::STATUS_APPROVED, (new entry($first->get('id')))->get('status'));
        $this->assertSame(entry::STATUS_APPROVED, (new entry($second->get('id')))->get('status'));
        $this->assertSame(entry::STATUS_SUBMITTED, (new entry($own->get('id')))->get('status'));
        $this->assertSame(entry::STATUS_DRAFT, (new entry($draft->get('id')))->get('status'));

        $this->expectException(\moodle_exception::class);
        review_manager::approve_many(range(1, review_manager::BULK_LIMIT + 1), (int) $this->approver->id);
    }

    /**
     * Submitting tells every approver except the member, and not suspended approvers.
     */
    public function test_submit_notifies_approvers(): void {
        $suspended = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $role = $this->getDataGenerator()->create_role();
        assign_capability('local/cpdlog:approve', CAP_ALLOW, $role, \context_system::instance());
        $this->getDataGenerator()->role_assign($role, $suspended->id);
        $this->getDataGenerator()->enrol_user($this->approver->id, $this->course->id);
        $details = (object) [
            'categoryid' => category::get_record(['shortname' => 'EA'])->get('id'),
            'courseid' => $this->course->id,
            'activitydate' => $this->period->get('startdate'),
            'hours' => 1,
        ];

        // A member's submission goes to both approvers.
        $messages = $this->redirectMessages();
        $entry = entry_manager::save_draft((int) $this->member->id, $details);
        entry_manager::submit($entry, (int) $this->member->id);
        $sent = $messages->get_messages();
        $recipients = array_map(fn($message) => (int) $message->useridto, $sent);
        sort($recipients);
        $expected = [(int) $this->approver->id, (int) $this->approver2->id];
        sort($expected);
        $this->assertSame($expected, $recipients);
        $this->assertSame('entrysubmitted', $sent[0]->eventtype);
        $this->assertSame('CPD activity submitted by Mary Member', $sent[0]->subject);
        $messages->clear();

        // An approver's own submission goes only to the other approver.
        $entry = entry_manager::save_draft((int) $this->approver->id, $details);
        entry_manager::submit($entry, (int) $this->approver->id);
        $sent = $messages->get_messages();
        $this->assertCount(1, $sent);
        $this->assertEquals($this->approver2->id, $sent[0]->useridto);
    }
}
