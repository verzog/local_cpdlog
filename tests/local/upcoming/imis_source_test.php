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
 * Tests for upcoming CPD from iMIS events.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local\upcoming;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use local_cpdlog\local\imis\client;
use local_cpdlog\local\imis\connection;
use local_cpdlog\local\upcoming;
use local_cpdlog\task\refresh_imis_events;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\RequestInterface;

/**
 * Tests for upcoming CPD from iMIS events, against a fake iMIS.
 */
#[CoversClass(imis_source::class)]
#[CoversClass(combined_source::class)]
#[CoversClass(client::class)]
#[CoversClass(connection::class)]
#[CoversClass(refresh_imis_events::class)]
final class imis_source_test extends \advanced_testcase
{
    /** @var string The fake iMIS site. */
    const BASE = 'https://imis.example.org';

    /** @var \stdClass The member, iMIS ID 12345. */
    private \stdClass $member;

    /** @var array Replies by path and query, each a list used in turn (the last repeats). */
    private array $replies = [];

    /** @var string[] Requests made, as METHOD path?query. */
    private array $requests = [];

    /**
     * Sets up the iMIS connection, iMIS as the source, a member and the fake iMIS.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Australia/Sydney');
        set_config('upcomingsource', upcoming::SOURCE_IMIS, 'local_cpdlog');
        set_config('imisbaseurl', self::BASE . '/', 'local_cpdlog');
        set_config('imisusername', 'moodle-service', 'local_cpdlog');
        set_config('imispassword', 'secret', 'local_cpdlog');
        $this->member = $this->getDataGenerator()->create_user(['idnumber' => '12345']);

        $handler = function (RequestInterface $request) {
            $uri = $request->getUri();
            $target = $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');
            $this->requests[] = $request->getMethod() . ' ' . $target;
            if ($target === '/token') {
                $token = ['access_token' => 'tok' . count($this->requests), 'expires_in' => 3600];
                return Create::promiseFor(new Response(200, [], json_encode($token)));
            }
            // The longest matching prefix answers, so /api/EventCPD is not taken for /api/Event.
            $prefixes = array_keys($this->replies);
            usort($prefixes, fn($a, $b) => strlen($b) <=> strlen($a));
            foreach ($prefixes as $prefix) {
                if (str_starts_with($target, $prefix)) {
                    $list = &$this->replies[$prefix];
                    return Create::promiseFor(count($list) > 1 ? array_shift($list) : $list[0]);
                }
            }
            return Create::promiseFor(new Response(404));
        };
        \core\di::set(\core\http_client::class, new \core\http_client(['handler' => HandlerStack::create($handler)]));
    }

    /**
     * Returns a date and time in Sydney some days from now, as iMIS sends it.
     *
     * @param int $days Days from today.
     * @param string $time The time.
     * @return string
     */
    private static function day(int $days, string $time = '18:30:00'): string {
        $tz = new \DateTimeZone('Australia/Sydney');
        return (new \DateTimeImmutable('today', $tz))->modify(sprintf('%+d days', $days))->format('Y-m-d') . 'T' . $time;
    }

    /**
     * Returns a JSON reply holding a list (PagedResult).
     *
     * @param array $items The items.
     * @param bool $hasnext Whether there is another page.
     * @param int $status HTTP status.
     * @return Response
     */
    private static function paged(array $items, bool $hasnext = false, int $status = 200): Response {
        return new Response($status, [], json_encode([
            'Items' => ['$values' => $items],
            'HasNext' => $hasnext,
            'NextOffset' => count($items),
        ]));
    }

    /**
     * Returns an iMIS event.
     *
     * @param string $id Its ID.
     * @param string $start Its start.
     * @param array $overrides Fields to change.
     * @return array
     */
    private static function event(string $id, string $start, array $overrides = []): array {
        return $overrides + [
            'EventId' => $id,
            'EventCode' => $id . '_CODE',
            'Name' => 'Event ' . $id,
            'StartDateTime' => $start,
            'Status' => 'A',
            'TemplateState' => 'NotATemplate',
            'PublishingInformation' => ['PublishingState' => 'Published'],
        ];
    }

    /**
     * Returns an EventCPD row (GenericEntityData) with typed hours.
     *
     * @param float $education Educational activities hours.
     * @param float $reviewing Reviewing performance hours.
     * @param float $outcomes Measuring outcomes hours.
     * @return array
     */
    private static function cpd(float $education, float $reviewing = 0, float $outcomes = 0): array {
        $props = [];
        $hours = [
            'EducationPoints' => $education,
            'ReviewingPerformancePoints' => $reviewing,
            'MeasuringOutcomesPoints' => $outcomes,
        ];
        foreach ($hours as $name => $value) {
            $props[] = ['Name' => $name, 'Value' => ['$type' => 'System.Decimal', '$value' => $value]];
        }
        return ['Properties' => ['$values' => $props]];
    }

    /**
     * Sets the fake iMIS's events, each event's CPD hours and the member's registrations.
     *
     * @param array $events Events.
     * @param array[] $cpd EventCPD rows by event ID.
     * @param string[] $registered Event IDs the member is registered for.
     */
    private function imis(array $events, array $cpd, array $registered = []): void {
        $this->replies['/api/Event'] = [self::paged($events)];
        foreach ($cpd as $id => $rows) {
            $this->replies['/api/EventCPD?EventKey=' . $id . '&'] = [self::paged($rows)];
        }
        foreach ($registered as $id) {
            $this->replies['/api/EventRegistration?EventId=' . $id . '&PartyId=12345'] = [
                new Response(200, [], json_encode(['EventRegistrationId' => 'R-' . $id, 'Status' => 'A'])),
            ];
        }
    }

    /**
     * Returns the member's lists.
     *
     * @return array[]
     */
    private function lists(): array {
        return upcoming::get_lists((int) $this->member->id);
    }

    /**
     * Active, published events with CPD hours are listed with their hours and categories, those
     * the member is registered for under Mine, and templates, inactive, hidden, past and later
     * events are left out.
     */
    public function test_lists(): void {
        $this->imis([
            self::event('E1', self::day(3)),
            self::event('E2', self::day(5)),
            self::event('T1', self::day(4), ['TemplateState' => 'StandardTemplate']),
            self::event('I1', self::day(4), ['Status' => 'I']),
            self::event('H1', self::day(4), ['PublishingInformation' => ['PublishingState' => 'Expired']]),
            self::event('N1', self::day(6)),
            self::event('P1', self::day(-2)),
            self::event('L1', self::day(90)),
        ], [
            'E1' => [self::cpd(1.5, 1)],
            'E2' => [self::cpd(0, 0, 2), self::cpd(0, 0, 0.5)],
            'T1' => [self::cpd(1)],
            'I1' => [self::cpd(1)],
            'H1' => [self::cpd(1)],
            'P1' => [self::cpd(1)],
            'L1' => [self::cpd(1)],
        ], ['E1']);

        $lists = $this->lists();
        $this->assertSame(['Event E1'], array_column($lists['mine'], 'title'));
        $this->assertSame(['Event E2'], array_column($lists['available'], 'title'));
        // Only usable events are kept: past ones are dropped, while later ones wait for their window.
        $cached = \cache::make('local_cpdlog', 'upcoming')->get(imis_source::CACHE_KEY);
        $this->assertSame(['E1', 'E2', 'L1'], array_column($cached->events, 'id'));
        $e1 = $lists['mine'][0];
        $this->assertSame('2.50', $e1['hours']);
        $this->assertSame('Educational activities, Reviewing performance', $e1['category']);
        $this->assertSame(self::BASE . '/iCore/Events/Event_display.aspx?EventKey=E1', $e1['url']);
        $this->assertStringEndsWith('06:30 PM', $e1['date']);
        $this->assertSame('2.50', $lists['available'][0]['hours']);
        $this->assertSame('Measuring outcomes', $lists['available'][0]['category']);

        // The service account signs in once per read, and is never sent anywhere but iMIS.
        $this->assertContains('POST /token', $this->requests);
        $this->assertSame(1, count(preg_grep('~^GET /api/Event\?StartDateTime=ge%3A~', $this->requests)));

        // Events without CPD hours are listed when the setting allows.
        set_config('imiscpdonly', 0, 'local_cpdlog');
        imis_source::refresh();
        $this->assertSame(['Event E2', 'Event N1'], array_column($this->lists()['available'], 'title'));
        $this->assertSame('', $this->lists()['available'][1]['hours']);
    }

    /**
     * Nothing is read from iMIS until the connection is set, or while Moodle is the only source.
     */
    public function test_not_configured(): void {
        set_config('imispassword', '', 'local_cpdlog');
        $this->assertSame(['mine' => [], 'available' => []], $this->lists());
        $this->assertSame([], $this->requests);

        set_config('imispassword', 'secret', 'local_cpdlog');
        set_config('upcomingsource', upcoming::SOURCE_MOODLE, 'local_cpdlog');
        $this->expectOutputString('');
        (new refresh_imis_events())->execute();
        $this->lists();
        $this->assertSame([], $this->requests);
    }

    /**
     * The list is read once and cached for everyone; members' registrations are cached too, and
     * the task reads the list again.
     */
    public function test_cache_and_task(): void {
        $this->imis([self::event('E1', self::day(3))], ['E1' => [self::cpd(1)]], ['E1']);
        $this->lists();
        $count = count($this->requests);
        $this->assertCount(1, $this->lists()['mine']);
        $this->assertCount($count, $this->requests);

        $this->imis([self::event('E1', self::day(3)), self::event('E2', self::day(4))], [
            'E1' => [self::cpd(1)],
            'E2' => [self::cpd(1)],
        ]);
        $this->expectOutputRegex('/Upcoming iMIS events read: 2\./');
        (new refresh_imis_events())->execute();
        $lists = $this->lists();
        $this->assertSame(['Event E1'], array_column($lists['mine'], 'title'));
        $this->assertSame(['Event E2'], array_column($lists['available'], 'title'));
        // Only the new event's registration was asked about.
        $this->assertCount(1, preg_grep('~EventRegistration\?EventId=E1~', $this->requests));
    }

    /**
     * When iMIS refuses the date filter the events are read unfiltered; a refused token is
     * renewed once; and a failure shows no iMIS events rather than an error.
     */
    public function test_imis_replies(): void {
        $this->replies['/api/Event?StartDateTime'] = [self::paged([], false, 400)];
        $this->replies['/api/Event?limit'] = [new Response(401), self::paged([self::event('E1', self::day(3))])];
        $this->replies['/api/EventCPD?EventKey=E1&'] = [self::paged([self::cpd(1)])];
        $this->assertSame(['Event E1'], array_column($this->lists()['available'], 'title'));
        // Signed in for the events, again after the 401, and once more to check registrations.
        $this->assertCount(3, preg_grep('~^POST /token~', $this->requests));

        \cache::make('local_cpdlog', 'upcoming')->purge();
        $this->replies = ['/api/Event' => [new Response(500)]];
        $this->assertSame(['mine' => [], 'available' => []], $this->lists());
        $this->assertDebuggingCalled();

        // A registration check that fails lists the event as available and is tried again later.
        $this->imis([self::event('E1', self::day(3))], ['E1' => [self::cpd(1)]]);
        $this->replies['/api/EventRegistration'] = [new Response(500)];
        $this->assertSame(['Event E1'], array_column($this->lists()['available'], 'title'));
        $this->assertDebuggingCalled();
        $this->replies['/api/EventRegistration'] = [new Response(200, [], json_encode(['EventRegistrationId' => 'R1']))];
        $cache = \cache::make('local_cpdlog', 'upcoming');
        $key = 'imisreg_' . md5(connection::get()->key() . "\0" . '12345');
        $cached = $cache->get($key);
        $this->assertSame([], $cached->events);
        $this->assertLessThanOrEqual(time() + imis_source::FAILURE_TTL, $cached->expires);
    }

    /**
     * Moodle and iMIS items are combined, soonest first, when both are chosen; the event link
     * follows the setting.
     */
    public function test_both_sources_and_link(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course([
            'fullname' => 'Dermoscopy basics',
            'startdate' => (new \DateTimeImmutable('today', \core_date::get_user_timezone_object()))->modify('+4 days')
                ->getTimestamp(),
            'customfield_cpdlog_hours' => 2,
        ]);
        $this->getDataGenerator()->enrol_user($this->member->id, $course->id);
        $this->imis([self::event('E1', self::day(3)), self::event('E2', self::day(5))], [
            'E1' => [self::cpd(1)],
            'E2' => [self::cpd(1)],
        ]);
        set_config('upcomingsource', upcoming::SOURCE_BOTH, 'local_cpdlog');
        set_config('imiseventurl', '~/Events/{EventCode}', 'local_cpdlog');

        $lists = $this->lists();
        $this->assertSame(['Dermoscopy basics'], array_column($lists['mine'], 'title'));
        $this->assertSame(['Event E1', 'Event E2'], array_column($lists['available'], 'title'));
        $this->assertSame(self::BASE . '/Events/E1_CODE', $lists['available'][0]['url']);

        set_config('imiseventurl', 'https://events.example.org/e/{EventId}', 'local_cpdlog');
        $this->assertSame('https://events.example.org/e/E1', $this->lists()['available'][0]['url']);
    }

    /**
     * Every page of a list is read, and changing the connection reads the list again.
     */
    public function test_paging_and_connection_change(): void {
        $this->replies['/api/Event?StartDateTime=ge%3A' . substr(self::day(0), 0, 10) . '&limit=100&offset=0'] = [
            self::paged([self::event('E1', self::day(3))], true),
        ];
        $this->replies['/api/Event?StartDateTime=ge%3A' . substr(self::day(0), 0, 10) . '&limit=100&offset=1'] = [
            self::paged([self::event('E2', self::day(4))]),
        ];
        $this->replies['/api/EventCPD'] = [self::paged([self::cpd(1)])];
        $this->assertSame(['Event E1', 'Event E2'], array_column($this->lists()['available'], 'title'));

        $count = count(preg_grep('~^GET /api/Event\?~', $this->requests));
        set_config('imisusername', 'another-service', 'local_cpdlog');
        $this->lists();
        $this->assertCount($count + 2, preg_grep('~^GET /api/Event\?~', $this->requests));
    }

    /**
     * Members without an iMIS ID see every iMIS event as available, and iMIS is not asked about them.
     */
    public function test_member_without_imis_id(): void {
        global $DB;
        $DB->set_field('user', 'idnumber', '', ['id' => $this->member->id]);
        $this->imis([self::event('E1', self::day(3))], ['E1' => [self::cpd(1)]], ['E1']);
        $this->assertSame(['Event E1'], array_column($this->lists()['available'], 'title'));
        $this->assertSame([], preg_grep('~EventRegistration~', $this->requests));
    }
}
