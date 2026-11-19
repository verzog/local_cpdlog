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
 * Upcoming CPD from iMIS events.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local\upcoming;

use local_cpdlog\local\imis\client;
use local_cpdlog\local\imis\connection;
use local_cpdlog\persistent\category;

/**
 * Upcoming CPD from iMIS events (decision 24).
 *
 * Events come from the iMIS Events API with their CPD hours from the EventCPD panel, as in wormiss.
 * The list is the same for everyone, so the scheduled task refresh_imis_events reads it and keeps it
 * in the cache; a page only reads iMIS itself when there is no list yet. An event is a member's own
 * when they have an iMIS registration for it, checked once per member per event and cached.
 */
class imis_source implements source
{
    /** @var string Cache key for the event list. */
    const CACHE_KEY = 'imisevents';

    /** @var int How long a member's registrations are kept, in seconds. */
    const REGISTRATION_TTL = 900;

    /** @var int How long a failed registration check is kept before trying again, in seconds. */
    const FAILURE_TTL = 300;

    /** @var int How old the event list may get, if the task stops running, before a page reads it again. */
    const MAX_AGE = DAYSECS;

    /** @var string[] EventCPD fields by logbook category short name. */
    const CPD_FIELDS = [
        'EA' => 'EducationPoints',
        'RP' => 'ReviewingPerformancePoints',
        'MO' => 'MeasuringOutcomesPoints',
    ];

    /** @var string[] Template states that are not real events. */
    const TEMPLATE_STATES = ['standardtemplate', 'defaulttemplate'];

    /** @var string[] Publishing states that are not shown to members. */
    const HIDDEN_STATES = ['archived', 'declined', 'deleted', 'expired', 'waitingforapproval', 'working'];

    /** @var string Status of an active event. */
    const ACTIVE = 'a';

    /** @var string Default link to an event: the iMIS event display page. */
    const DEFAULT_EVENT_URL = '/iCore/Events/Event_display.aspx?EventKey={EventId}';

    /**
     * Returns the upcoming iMIS events a member can see, soonest first.
     *
     * @param int $userid The member.
     * @param int $from The start of the window.
     * @param int $to The end of the window.
     * @return \stdClass[]
     */
    public function get_items(int $userid, int $from, int $to): array {
        $connection = connection::get();
        if (!$connection->is_configured()) {
            return [];
        }
        $cached = \cache::make('local_cpdlog', 'upcoming')->get(self::CACHE_KEY);
        if ($cached === false || $cached->connection !== $connection->key() || $cached->time < time() - self::MAX_AGE) {
            try {
                $cached = self::refresh($connection);
            } catch (\moodle_exception $e) {
                // The panel shows nothing from iMIS rather than an error.
                debugging('Upcoming CPD could not read events from iMIS: ' . $e->getMessage(), DEBUG_DEVELOPER);
                return [];
            }
        }

        $events = array_values(array_filter($cached->events, fn($event) => $event->timestart >= $from && $event->timestart < $to));
        $mine = self::registrations($connection, $userid, array_column($events, 'id'));
        $names = self::category_names();
        $items = [];
        foreach ($events as $event) {
            $hours = array_sum($event->hours);
            $categories = [];
            foreach ($event->hours as $shortname => $value) {
                if ($value > 0 && isset($names[$shortname])) {
                    $categories[] = $names[$shortname];
                }
            }
            $items[] = (object) [
                'type' => 'event',
                'title' => $event->name,
                'coursename' => '',
                'timestart' => $event->timestart,
                'allday' => false,
                'hours' => $hours > 0 ? format_float($hours, 2) : '',
                'category' => implode(', ', $categories),
                'url' => self::event_url($connection, $event),
                'mine' => !empty($mine[$event->id]),
            ];
        }
        return $items;
    }

    /**
     * Reads the upcoming events and their CPD hours from iMIS and caches them, for every member.
     *
     * Events are read from the start of today up to a year ahead, so any window fits.
     *
     * @param connection|null $connection The connection; the one in use if null.
     * @return \stdClass The cached list: connection, time and events.
     * @throws \moodle_exception If iMIS cannot be read.
     */
    public static function refresh(?connection $connection = null): \stdClass {
        $connection = $connection ?? connection::get();
        $client = new client($connection);
        $today = (new \DateTimeImmutable('now', \core_date::get_server_timezone_object()))->setTime(0, 0);
        $until = $today->modify('+367 days')->getTimestamp();
        try {
            $rows = $client->get_all('/api/Event?StartDateTime=' . rawurlencode('ge:' . $today->format('Y-m-d')));
        } catch (\moodle_exception $e) {
            // Some iMIS versions refuse the date filter; the dates are checked here anyway.
            unset($e);
            $rows = $client->get_all('/api/Event');
        }

        $cpdonly = self::cpd_only();
        $events = [];
        foreach ($rows as $row) {
            $event = self::normalise($row);
            if (!$event || $event->timestart < $today->getTimestamp() || $event->timestart >= $until || !self::is_shown($event)) {
                continue;
            }
            $event->hours = self::event_hours($client, $event->id);
            if ($cpdonly && array_sum($event->hours) <= 0) {
                continue;
            }
            unset($event->status, $event->template, $event->publishing);
            $events[] = $event;
        }
        usort($events, fn($a, $b) => [$a->timestart, $a->name] <=> [$b->timestart, $b->name]);

        $cached = (object) ['connection' => $connection->key(), 'time' => time(), 'events' => $events];
        \cache::make('local_cpdlog', 'upcoming')->set(self::CACHE_KEY, $cached);
        return $cached;
    }

    /**
     * Turns an iMIS EventData row into the fields used here.
     *
     * @param \stdClass $row The row.
     * @return \stdClass|null id, code, name, timestart, status, template and publishing; null if unusable.
     */
    protected static function normalise(\stdClass $row): ?\stdClass {
        $id = trim((string) client::value($row->EventId ?? ''));
        $name = trim((string) client::value($row->Name ?? ''));
        $timestart = self::timestamp((string) client::value($row->StartDateTime ?? ''));
        if ($id === '' || $name === '' || $timestart === null || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            return null;
        }
        $publishing = $row->PublishingInformation ?? null;
        return (object) [
            'id' => $id,
            'code' => trim((string) client::value($row->EventCode ?? '')),
            'name' => $name,
            'timestart' => $timestart,
            'status' => strtolower(trim((string) client::value($row->Status ?? ''))),
            'template' => strtolower(trim((string) client::value($row->TemplateState ?? ''))),
            'publishing' => $publishing instanceof \stdClass
                ? strtolower(trim((string) client::value($publishing->PublishingState ?? ''))) : '',
        ];
    }

    /**
     * Whether an event is a real, active, published one.
     *
     * @param \stdClass $event From normalise().
     * @return bool
     */
    protected static function is_shown(\stdClass $event): bool {
        return $event->status === self::ACTIVE
            && !in_array($event->template, self::TEMPLATE_STATES, true)
            && !in_array($event->publishing, self::HIDDEN_STATES, true);
    }

    /**
     * Returns an event's CPD hours per logbook category, added up across its EventCPD rows.
     *
     * @param client $client The client.
     * @param string $eventid The event.
     * @return float[] Hours by category short name.
     */
    protected static function event_hours(client $client, string $eventid): array {
        $hours = array_fill_keys(array_keys(self::CPD_FIELDS), 0.0);
        try {
            $rows = $client->get_all('/api/EventCPD?EventKey=' . rawurlencode($eventid));
        } catch (\moodle_exception $e) {
            // An event without CPD hours, or with none readable, is shown without hours.
            unset($e);
            return $hours;
        }
        foreach ($rows as $row) {
            $properties = client::properties($row);
            foreach (self::CPD_FIELDS as $shortname => $field) {
                $value = $properties[$field] ?? 0;
                $hours[$shortname] += is_numeric($value) ? max(0.0, (float) $value) : 0.0;
            }
        }
        return array_map(fn($value) => round($value, 2), $hours);
    }

    /**
     * Returns which of these events the member is registered for, from the cache or from iMIS.
     *
     * @param connection $connection The connection.
     * @param int $userid The member.
     * @param string[] $eventids The events.
     * @return bool[] Keyed by event id.
     */
    protected static function registrations(connection $connection, int $userid, array $eventids): array {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id, username, idnumber, email');
        $partyid = $user ? connection::member_id($user) : '';
        if ($partyid === '' || !$eventids) {
            return [];
        }
        $cache = \cache::make('local_cpdlog', 'upcoming');
        $key = 'imisreg_' . md5($connection->key() . "\0" . $partyid);
        $cached = $cache->get($key);
        if ($cached === false || $cached->expires < time()) {
            $cached = (object) ['expires' => time() + self::REGISTRATION_TTL, 'events' => []];
        }
        $missing = array_diff($eventids, array_keys($cached->events));
        if ($missing) {
            try {
                $client = new client($connection);
                foreach ($missing as $eventid) {
                    $path = '/api/EventRegistration?EventId=' . rawurlencode($eventid) . '&PartyId=' . rawurlencode($partyid);
                    // A 404 from iMIS means the member has no registration.
                    $registration = $client->get($path);
                    $cached->events[$eventid] = $registration !== null && !empty($registration->EventRegistrationId);
                }
            } catch (\moodle_exception $e) {
                // Try again a little later; until then the events are listed as available.
                debugging('Upcoming CPD could not read iMIS registrations: ' . $e->getMessage(), DEBUG_DEVELOPER);
                $cached->expires = min($cached->expires, time() + self::FAILURE_TTL);
            }
            $cache->set($key, $cached);
        }
        return $cached->events;
    }

    /**
     * Returns the link to an event in iMIS, from the setting's pattern.
     *
     * @param connection $connection The connection.
     * @param \stdClass $event The event.
     * @return string
     */
    protected static function event_url(connection $connection, \stdClass $event): string {
        $pattern = trim((string) get_config('local_cpdlog', 'imiseventurl'));
        $pattern = $pattern !== '' ? $pattern : self::DEFAULT_EVENT_URL;
        $url = str_replace(['{EventId}', '{EventCode}'], [rawurlencode($event->id), rawurlencode($event->code)], $pattern);
        if (!preg_match('~^https?://~i', $url)) {
            $url = $connection->baseurl . '/' . ltrim(preg_replace('~^\~/~', '', $url), '/');
        }
        return $url;
    }

    /**
     * Whether only events with CPD hours are listed. The setting defaults to on; unset counts as on.
     *
     * @return bool
     */
    public static function cpd_only(): bool {
        $value = get_config('local_cpdlog', 'imiscpdonly');
        return $value === false || (bool) $value;
    }

    /**
     * Returns the names of the logbook categories that EventCPD hours count towards.
     *
     * @return string[] Plain-text names by short name.
     */
    protected static function category_names(): array {
        $names = [];
        foreach (array_keys(self::CPD_FIELDS) as $shortname) {
            $category = category::get_record(['shortname' => $shortname]);
            if ($category) {
                $name = format_string($category->get('name'), true, ['context' => \context_system::instance(), 'escape' => false]);
                $names[$shortname] = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return $names;
    }

    /**
     * Reads an iMIS date and time, which has no timezone and is in the site's local time.
     *
     * @param string $value Such as 2026-12-01T09:00:00.
     * @return int|null
     */
    protected static function timestamp(string $value): ?int {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', trim($value))) {
            return null;
        }
        try {
            return (new \DateTimeImmutable(trim($value), \core_date::get_server_timezone_object()))->getTimestamp();
        } catch (\Exception $e) {
            unset($e);
            return null;
        }
    }
}
