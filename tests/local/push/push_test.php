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
 * Tests for sending approved CPD to iMIS.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local\push;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use local_cpdlog\local\import\auditor;
use local_cpdlog\local\import\imis_iqa_source;
use local_cpdlog\local\review_manager;
use local_cpdlog\observer;
use local_cpdlog\persistent\entry;
use local_cpdlog\task\push_entry;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\RequestInterface;

/**
 * Tests for sending approved CPD to iMIS, against a fake iMIS.
 */
#[CoversClass(pusher::class)]
#[CoversClass(payload::class)]
#[CoversClass(push_entry::class)]
#[CoversClass(observer::class)]
final class push_test extends \advanced_testcase
{
    /** @var \stdClass The member, iMIS ID 1001. */
    private \stdClass $member;

    /** @var \stdClass An approver. */
    private \stdClass $approver;

    /** @var array[] Replies by METHOD path prefix (a Response, or a function run when the request
     *              arrives that returns one), each list used in turn (the last repeats). */
    private array $replies = [];

    /** @var array[] Requests made: method, target and decoded body. */
    private array $requests = [];

    /**
     * Sets up the iMIS connection, a 2026 period, a member, an approver and the fake iMIS.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Australia/Sydney');
        set_config('imisbaseurl', 'https://imis.example.org', 'local_cpdlog');
        set_config('imisusername', 'moodle-service', 'local_cpdlog');
        set_config('imispassword', 'secret', 'local_cpdlog');
        $this->getDataGenerator()->get_plugin_generator('local_cpdlog')
            ->create_period(['name' => '2026', 'firstday' => '01/01/2026', 'lastday' => '31/12/2026']);
        $this->member = $this->getDataGenerator()->create_user(['idnumber' => '1001', 'username' => 'ann']);
        $this->approver = $this->getDataGenerator()->create_user();

        $handler = function (RequestInterface $request) {
            $uri = $request->getUri();
            // The key's | is sent encoded, as %7C; iMIS reads the decoded path.
            $target = rawurldecode($uri->getPath()) . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');
            $this->requests[] = [
                'method' => $request->getMethod(),
                'target' => $target,
                'body' => json_decode((string) $request->getBody(), true),
            ];
            if ($target === '/token') {
                return Create::promiseFor(new Response(200, [], json_encode(['access_token' => 't', 'expires_in' => 3600])));
            }
            $key = $request->getMethod() . ' ' . $target;
            foreach ($this->replies as $prefix => &$list) {
                if (str_starts_with($key, $prefix)) {
                    $reply = count($list) > 1 ? array_shift($list) : $list[0];
                    return Create::promiseFor($reply instanceof \Closure ? $reply() : $reply);
                }
            }
            return Create::promiseFor(new Response(404));
        };
        \core\di::set(\core\http_client::class, new \core\http_client(['handler' => HandlerStack::create($handler)]));
    }

    /**
     * Creates a submitted entry for the member.
     *
     * @param array $overrides Fields to change.
     * @return entry
     */
    private function entry(array $overrides = []): entry {
        return $this->getDataGenerator()->get_plugin_generator('local_cpdlog')->create_entry($overrides + [
            'user' => 'ann',
            'period' => '2026',
            'category' => 'RP',
            'day' => '10/03/2026',
            'hours' => 2.5,
            'activityname' => 'Skin audit',
            'provider' => 'SCCA',
            'status' => entry::STATUS_SUBMITTED,
        ]);
    }

    /**
     * Returns an entry's push state.
     *
     * @param entry $entry The entry.
     * @return \stdClass|false
     */
    private static function state(entry $entry) {
        global $DB;
        return $DB->get_record(pusher::TABLE, ['entryid' => $entry->get('id')]);
    }

    /**
     * Returns a body's properties as name => value, typed values unwrapped.
     *
     * @param array $body GenericEntityData.
     * @return array
     */
    private static function props(array $body): array {
        $props = [];
        foreach ($body['Properties']['$values'] as $prop) {
            $value = $prop['Value'] ?? null;
            $props[$prop['Name']] = is_array($value) && array_key_exists('$value', $value) ? $value['$value'] : $value;
        }
        return $props;
    }

    /**
     * Returns the writes made to iMIS.
     *
     * @return array[]
     */
    private function writes(): array {
        return array_values(array_filter($this->requests, fn($r) => in_array($r['method'], ['POST', 'PUT'], true)
            && $r['target'] !== '/token'));
    }

    /**
     * Nothing is queued while sending is off, which it is by default.
     */
    public function test_off_by_default(): void {
        $entry = $this->entry();
        review_manager::approve($entry, (int) $this->approver->id);
        $this->assertFalse(self::state($entry));
        $this->assertSame([], $this->requests);
    }

    /**
     * Approving queues the entry; in a dry run the body is recorded with SCCA's CPDLogbook fields,
     * and nothing is sent.
     */
    public function test_dry_run(): void {
        set_config('pushenabled', 1, 'local_cpdlog');
        $entry = $this->entry();
        review_manager::approve($entry, (int) $this->approver->id);
        $this->assertSame(pusher::STATUS_PENDING, self::state($entry)->status);
        $this->assertSame(pusher::STATUS_PENDING, (new entry($entry->get('id')))->get('syncstatus'));

        $this->expectOutputRegex('/sent to iMIS: dryrun/');
        $this->runAdhocTasks(push_entry::class);
        $state = self::state($entry);
        $this->assertSame(pusher::STATUS_DRYRUN, $state->status);
        $this->assertSame('create', $state->action);
        $this->assertSame([], $this->requests);

        $body = json_decode($state->payload, true);
        $this->assertSame('CPDLogbook', $body['EntityTypeName']);
        $this->assertSame(['1001'], $body['PrimaryParentIdentity']['IdentityElements']['$values']);
        $this->assertArrayNotHasKey('Identity', $body);
        $props = self::props($body);
        $this->assertSame('1001', $props['ID']);
        $this->assertSame('2026-03-10T00:00:00', $props['ActivityDate']);
        $this->assertSame('Skin audit, SCCA (external)', $props['CourseTitle']);
        $this->assertEquals(0, $props['EducationHours']);
        $this->assertEquals(2.5, $props['ReviewingPerformanceHours']);
        $this->assertEquals(0, $props['MeasuringOutcomesHours']);
        $this->assertSame('MOODLE', $props['OriginatingReferenceType']);
        $this->assertSame('cpdlog:' . $entry->get('id'), $props['OriginatingReferenceValue']);
        $this->assertSame('1', $props['SCCAActivity']);
    }

    /**
     * A sent entry keeps the key iMIS gave its row; reversing it updates that row with no hours and
     * a marked title, never deleting it.
     */
    public function test_send_and_reverse(): void {
        set_config('pushenabled', 1, 'local_cpdlog');
        set_config('pushdryrun', 0, 'local_cpdlog');
        $this->replies['POST /api/CPDLogbook'] = [new Response(201, [], json_encode([
            'Identity' => ['IdentityElements' => ['$values' => ['1001', '3']]],
        ]))];
        $this->replies['PUT /api/CPDLogbook/~1001|3'] = [new Response(200, [], '')];
        $entry = $this->entry();
        review_manager::approve($entry, (int) $this->approver->id);
        $this->assertSame(pusher::STATUS_SENT, pusher::send((int) $entry->get('id')));
        $state = self::state($entry);
        $this->assertSame('1001|3', $state->imiskey);
        $this->assertNotNull($state->timesent);
        $this->assertSame(pusher::STATUS_SENT, (new entry($entry->get('id')))->get('syncstatus'));
        $this->assertSame('POST', $this->writes()[0]['method']);

        review_manager::reverse(new entry($entry->get('id')), (int) $this->approver->id, 'Duplicate');
        $this->assertSame('reverse', self::state($entry)->action);
        $this->assertSame(pusher::STATUS_SENT, pusher::send((int) $entry->get('id')));
        $put = $this->writes()[1];
        $this->assertSame('PUT', $put['method']);
        $this->assertSame('/api/CPDLogbook/~1001|3', $put['target']);
        $this->assertSame(['1001', '3'], $put['body']['Identity']['IdentityElements']['$values']);
        $props = self::props($put['body']);
        $this->assertEquals(0, $props['ReviewingPerformanceHours']);
        $this->assertSame('Reversed: Skin audit, SCCA (external)', $props['CourseTitle']);
        $this->assertSame([], array_filter($this->requests, fn($r) => $r['method'] === 'DELETE'));
    }

    /**
     * A failure is recorded with iMIS's reason and thrown so the task retries, until it is left
     * for staff; sending it again starts afresh.
     */
    public function test_failure(): void {
        set_config('pushenabled', 1, 'local_cpdlog');
        set_config('pushdryrun', 0, 'local_cpdlog');
        $this->replies['POST /api/CPDLogbook'] = [new Response(400, [], json_encode([
            'Message' => 'Validation failed',
            'ValidationResults' => ['Errors' => ['$values' => [['Message' => 'ActivityDate is required']]]],
        ]))];
        $entry = $this->entry();
        review_manager::approve($entry, (int) $this->approver->id);
        for ($attempt = 1; $attempt < pusher::MAX_ATTEMPTS; $attempt++) {
            try {
                pusher::send((int) $entry->get('id'));
                $this->fail('Expected an exception');
            } catch (\moodle_exception $e) {
                $this->assertSame('imis:writefailed', $e->errorcode);
            }
        }
        $this->assertSame(pusher::STATUS_FAILED, pusher::send((int) $entry->get('id')));
        $state = self::state($entry);
        $this->assertSame(pusher::MAX_ATTEMPTS, (int) $state->attempts);
        $this->assertStringContainsString('Validation failed ActivityDate is required', $state->error);
        $this->assertNull($state->imiskey);

        $this->assertTrue(pusher::queue((int) $entry->get('id')));
        $this->assertSame(0, (int) self::state($entry)->attempts);
    }

    /**
     * Entries without an iMIS ID, from other sources, not approved, or reversed before ever being
     * sent are not sent; course completions released by staff are, through the approval event.
     */
    public function test_what_is_sent(): void {
        global $DB;
        set_config('pushenabled', 1, 'local_cpdlog');
        set_config('pushdryrun', 0, 'local_cpdlog');
        $this->assertFalse(pusher::queue((int) $this->entry()->get('id')));
        foreach ([entry::SOURCE_IMIS, entry::SOURCE_IMAGEBLOG, entry::SOURCE_IMPORT] as $i => $source) {
            $other = $this->entry(['status' => entry::STATUS_APPROVED, 'source' => $source, 'externalref' => 'x' . $i]);
            $this->assertFalse(pusher::queue((int) $other->get('id')), $source);
        }

        $reversed = $this->entry(['status' => entry::STATUS_APPROVED]);
        review_manager::reverse($reversed, (int) $this->approver->id, 'Wrong');
        $this->assertFalse(self::state($reversed));

        $DB->set_field('user', 'idnumber', '', ['id' => $this->member->id]);
        $noid = $this->entry(['status' => entry::STATUS_APPROVED]);
        \local_cpdlog\event\entry_approved::create_from_entry($noid)->trigger();
        $this->assertSame(pusher::STATUS_SKIPPED, pusher::send((int) $noid->get('id')));
        $this->assertSame(get_string('push:nomember', 'local_cpdlog'), self::state($noid)->error);
        $this->assertSame([], $this->writes());
    }

    /**
     * Activity records carry every column, typed, with the hours in UNITS; the key is the SEQN.
     */
    public function test_activity_target(): void {
        set_config('pushenabled', 1, 'local_cpdlog');
        set_config('pushdryrun', 0, 'local_cpdlog');
        set_config('pushtarget', payload::TARGET_ACTIVITY, 'local_cpdlog');
        set_config('pushactivitytype', 'MOODLECPD', 'local_cpdlog');
        $this->replies['POST /api/Activity'] = [new Response(201, [], json_encode([
            'Properties' => ['$values' => [['Name' => 'SEQN', 'Value' => ['$type' => 'System.Int32', '$value' => 77]]]],
        ]))];
        $this->replies['PUT /api/Activity/77'] = [new Response(200, [], '')];
        $entry = $this->entry();
        review_manager::approve($entry, (int) $this->approver->id);
        pusher::send((int) $entry->get('id'));
        $this->assertSame('77', self::state($entry)->imiskey);

        $post = $this->writes()[0];
        $this->assertSame('/api/Activity', $post['target']);
        $props = self::props($post['body']);
        $this->assertCount(44, $props);
        $this->assertSame('MOODLECPD', $props['ACTIVITY_TYPE']);
        $this->assertSame('RP', $props['CATEGORY']);
        $this->assertEquals(2.5, $props['UNITS']);
        $this->assertSame('System.Decimal', $post['body']['Properties']['$values'][43]['Value']['$type']);
        $this->assertSame('cpdlog:' . $entry->get('id'), $props['NOTE_2']);
        $this->assertSame('1001', $props['PartyId']);

        review_manager::reverse(new entry($entry->get('id')), (int) $this->approver->id, 'Wrong');
        pusher::send((int) $entry->get('id'));
        $put = $this->writes()[1];
        $this->assertSame('/api/Activity/77', $put['target']);
        $this->assertEquals(77, self::props($put['body'])['SEQN']);
        $this->assertEquals(0, self::props($put['body'])['UNITS']);
    }

    /**
     * Incomplete settings, or a category without an hours field, skip the entry with the reason.
     */
    public function test_settings_problems(): void {
        set_config('pushenabled', 1, 'local_cpdlog');
        set_config('pushmaphours', 'EA=EducationHours', 'local_cpdlog');
        $entry = $this->entry(['status' => entry::STATUS_APPROVED]);
        pusher::queue((int) $entry->get('id'));
        $this->assertSame(pusher::STATUS_SKIPPED, pusher::send((int) $entry->get('id')));
        $this->assertStringContainsString('RP', self::state($entry)->error);

        set_config('pushmapmember', '', 'local_cpdlog');
        $this->assertSame(pusher::STATUS_SKIPPED, pusher::send((int) $entry->get('id')));
        $this->assertStringContainsString('pushmapmember', self::state($entry)->error);
    }

    /**
     * Rows Moodle wrote are never imported back: by their key, or by their origin type.
     */
    public function test_import_loop_guard(): void {
        global $DB;
        $entry = $this->entry(['status' => entry::STATUS_APPROVED]);
        $DB->insert_record(pusher::TABLE, (object) [
            'entryid' => $entry->get('id'),
            'target' => 'object',
            'imiskey' => '1001|3',
            'status' => pusher::STATUS_SENT,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        set_config('importiqa', '$/CPD', 'local_cpdlog');
        foreach (['member' => 'ID', 'key' => 'Ordinal', 'date' => 'ActivityDate', 'origin' => 'Origin'] as $name => $column) {
            set_config('importmap' . $name, $column, 'local_cpdlog');
        }
        set_config('importmaphours', 'RP=ReviewingPerformanceHours', 'local_cpdlog');
        $row = fn(string $ordinal, string $origin) => ['Properties' => ['$values' => [
            ['Name' => 'ID', 'Value' => '1001'],
            ['Name' => 'Ordinal', 'Value' => $ordinal],
            ['Name' => 'ActivityDate', 'Value' => '2026-04-01'],
            ['Name' => 'ReviewingPerformanceHours', 'Value' => '1'],
            ['Name' => 'Origin', 'Value' => $origin],
        ]]];
        $this->replies['GET /api/iqa'] = [new Response(200, [], json_encode([
            'Items' => ['$values' => [$row('3', ''), $row('4', 'MOODLE'), $row('5', 'COURSE')]],
            'HasNext' => false,
        ]))];
        $run = auditor::run(new imis_iqa_source(), '', 0);
        $rows = array_column($DB->get_records(auditor::ROW_TABLE, ['runid' => $run->id]), null, 'rowkey');
        $this->assertSame('pushed', $rows['3']->reason);
        $this->assertEquals($entry->get('id'), $rows['3']->matchentryid);
        // Linked to the member, so privacy requests and data deletion find them.
        $this->assertEquals($this->member->id, $rows['3']->userid);
        $this->assertEquals($this->member->id, $rows['4']->userid);
        $this->assertSame('pushed', $rows['4']->reason);
        $this->assertSame('new', $rows['5/RP']->outcome);
    }

    /**
     * Switching sending off stops queued and retrying sends from writing to iMIS; they wait to be
     * sent again.
     */
    public function test_switched_off_after_queueing(): void {
        set_config('pushenabled', 1, 'local_cpdlog');
        set_config('pushdryrun', 0, 'local_cpdlog');
        $entry = $this->entry();
        review_manager::approve($entry, (int) $this->approver->id);
        set_config('pushenabled', 0, 'local_cpdlog');
        $this->assertSame(pusher::STATUS_SKIPPED, pusher::send((int) $entry->get('id')));
        $this->assertSame(get_string('push:offnow', 'local_cpdlog'), self::state($entry)->error);
        $this->assertSame([], $this->writes());
    }

    /**
     * A reversal made while the row is being created still reaches iMIS: the send applies it once
     * the row exists, and queueing a reversal does not wait for the key.
     */
    public function test_reversal_during_create(): void {
        global $DB;
        set_config('pushenabled', 1, 'local_cpdlog');
        set_config('pushdryrun', 0, 'local_cpdlog');
        $entry = $this->entry();
        review_manager::approve($entry, (int) $this->approver->id);
        $this->assertTrue(pusher::queue((int) $entry->get('id')));
        $entryid = (int) $entry->get('id');
        // While iMIS takes a while to answer, staff reverse the entry.
        $this->replies['POST /api/CPDLogbook'] = [function () use ($entryid) {
            global $DB;
            $DB->set_field(entry::TABLE, 'status', entry::STATUS_REVERSED, ['id' => $entryid]);
            return new Response(201, [], json_encode(['Identity' => ['IdentityElements' => ['$values' => ['1001', '8']]]]));
        }];
        $this->replies['PUT /api/CPDLogbook/~1001|8'] = [new Response(200, [], '')];
        $this->assertSame(pusher::STATUS_SENT, pusher::send($entryid));
        $writes = $this->writes();
        $this->assertSame(['POST', 'PUT'], array_column($writes, 'method'));
        $this->assertEquals(0, self::props($writes[1]['body'])['ReviewingPerformanceHours']);
        $this->assertSame('reverse', self::state($entry)->action);

        // Queued while the first send was waiting, a reversal is not dropped for want of a key.
        $other = $this->entry(['day' => '11/03/2026']);
        review_manager::approve($other, (int) $this->approver->id);
        review_manager::reverse(new entry($other->get('id')), (int) $this->approver->id, 'Wrong');
        $this->assertSame('reverse', self::state($other)->action);
        $this->assertSame(pusher::STATUS_SKIPPED, pusher::send((int) $other->get('id')));
        $this->assertSame(get_string('push:notsent', 'local_cpdlog'), self::state($other)->error);
    }

    /**
     * A row iMIS created without saying its key is never created again by a resend.
     */
    public function test_no_key_is_not_created_twice(): void {
        set_config('pushenabled', 1, 'local_cpdlog');
        set_config('pushdryrun', 0, 'local_cpdlog');
        $this->replies['POST /api/CPDLogbook'] = [new Response(201, [], '')];
        $entry = $this->entry();
        review_manager::approve($entry, (int) $this->approver->id);
        $this->assertSame(pusher::STATUS_SENT, pusher::send((int) $entry->get('id')));
        $this->assertNull(self::state($entry)->imiskey);

        pusher::queue((int) $entry->get('id'));
        $this->assertSame(pusher::STATUS_SKIPPED, pusher::send((int) $entry->get('id')));
        $this->assertSame(get_string('push:nokey', 'local_cpdlog'), self::state($entry)->error);
        $this->assertCount(1, $this->writes());
    }

    /**
     * An existing row is changed where it was written, even after the target setting changes.
     */
    public function test_original_destination(): void {
        set_config('pushenabled', 1, 'local_cpdlog');
        set_config('pushdryrun', 0, 'local_cpdlog');
        $this->replies['POST /api/CPDLogbook'] = [new Response(201, [], json_encode([
            'Identity' => ['IdentityElements' => ['$values' => ['1001', '3']]],
        ]))];
        $this->replies['PUT /api/CPDLogbook/~1001|3'] = [new Response(200, [], '')];
        $entry = $this->entry();
        review_manager::approve($entry, (int) $this->approver->id);
        pusher::send((int) $entry->get('id'));
        $this->assertSame('CPDLogbook', self::state($entry)->entity);

        set_config('pushtarget', payload::TARGET_ACTIVITY, 'local_cpdlog');
        review_manager::reverse(new entry($entry->get('id')), (int) $this->approver->id, 'Wrong');
        $this->assertSame(pusher::STATUS_SENT, pusher::send((int) $entry->get('id')));
        $put = $this->writes()[1];
        $this->assertSame('/api/CPDLogbook/~1001|3', $put['target']);
        $this->assertSame('CPDLogbook', $put['body']['EntityTypeName']);
    }
}
