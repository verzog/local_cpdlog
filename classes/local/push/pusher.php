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
 * Pushes approved CPD entries to iMIS.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local\push;

use local_cpdlog\local\imis\client;
use local_cpdlog\local\imis\connection;
use local_cpdlog\persistent\entry;

/**
 * Sends approved entries logged in Moodle to iMIS, and marks them there when reversed (decision 26).
 *
 * Approving or reversing an entry queues it; the adhoc task push_entry sends it. Each entry has one
 * row in local_cpdlog_push recording the iMIS row it became and how the last send went. Off by
 * default, and in dry run by default: a dry run records the body it would send, and sends nothing.
 * iMIS rows are never deleted.
 */
class pusher
{
    /** @var string Table of push states. */
    const TABLE = 'local_cpdlog_push';

    /** @var string Waiting to be sent. */
    const STATUS_PENDING = 'pending';

    /** @var string Sent. */
    const STATUS_SENT = 'sent';

    /** @var string Recorded in a dry run, not sent. */
    const STATUS_DRYRUN = 'dryrun';

    /** @var string The last send failed. */
    const STATUS_FAILED = 'failed';

    /** @var string Not sent, and not worth retrying until something changes (no iMIS ID, say). */
    const STATUS_SKIPPED = 'skipped';

    /** @var int Sends tried before a failure is left for staff to retry. */
    const MAX_ATTEMPTS = 5;

    /** @var string Lock type for one entry's sends. */
    const LOCK_TYPE = 'local_cpdlog_push';

    /**
     * Whether pushing is switched on. Off by default.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool) get_config('local_cpdlog', 'pushenabled');
    }

    /**
     * Whether sends are only recorded, not made. On by default; unset counts as on.
     *
     * @return bool
     */
    public static function is_dry_run(): bool {
        $value = get_config('local_cpdlog', 'pushdryrun');
        return $value === false || (bool) $value;
    }

    /**
     * Queues an entry to be sent, when it is one Moodle owns and approval or reversal makes it due.
     *
     * @param int $entryid The entry.
     * @return bool Whether it was queued.
     */
    public static function queue(int $entryid): bool {
        global $DB;
        if (!self::is_enabled()) {
            return false;
        }
        $entry = entry::get_record(['id' => $entryid]);
        if (!$entry || $entry->get('source') !== entry::SOURCE_MOODLE) {
            return false;
        }
        $state = $DB->get_record(self::TABLE, ['entryid' => $entryid]);
        $status = $entry->get('status');
        // A reversal matters once the entry has been queued or sent: its first send may still be under
        // way, or about to create the row. The send decides what is due when it runs.
        if ($status !== entry::STATUS_APPROVED && !($status === entry::STATUS_REVERSED && $state)) {
            return false;
        }
        $now = time();
        $record = (object) [
            'entryid' => $entryid,
            'target' => $state->target ?? payload::target(),
            'entity' => $state->entity ?? payload::entity(),
            'status' => self::STATUS_PENDING,
            'action' => self::action($entry, $state ?: null),
            'error' => null,
            'attempts' => 0,
            'timemodified' => $now,
        ];
        if ($state) {
            $record->id = $state->id;
            $DB->update_record(self::TABLE, $record);
        } else {
            $record->timecreated = $now;
            $DB->insert_record(self::TABLE, $record);
        }
        $DB->set_field(entry::TABLE, 'syncstatus', self::STATUS_PENDING, ['id' => $entryid]);
        $task = new \local_cpdlog\task\push_entry();
        $task->set_custom_data(['entryid' => $entryid]);
        \core\task\manager::queue_adhoc_task($task, true);
        return true;
    }

    /**
     * Returns what sending the entry does: create its iMIS row, update it, mark it reversed, or
     * nothing until staff reconcile a row iMIS created without saying its key.
     *
     * @param entry $entry The entry.
     * @param \stdClass|null $state Its push state, if any.
     * @return string create, update, reverse or reconcile.
     */
    protected static function action(entry $entry, ?\stdClass $state): string {
        $created = $state && ($state->imiskey || $state->timesent);
        if ($state && !$state->imiskey && $state->timesent) {
            return 'reconcile';
        }
        if ($entry->get('status') === entry::STATUS_REVERSED) {
            return 'reverse';
        }
        return $created ? 'update' : 'create';
    }

    /**
     * Sends an entry, under its lock. A failure is recorded and thrown, so the task retries, until
     * MAX_ATTEMPTS, after which it waits for staff.
     *
     * @param int $entryid The entry.
     * @return string The resulting status.
     * @throws \moodle_exception When iMIS fails and the task should retry.
     */
    public static function send(int $entryid): string {
        global $DB;
        $state = $DB->get_record(self::TABLE, ['entryid' => $entryid]);
        $entry = entry::get_record(['id' => $entryid]);
        if (!$state) {
            return '';
        }
        if (!$entry) {
            $DB->delete_records(self::TABLE, ['id' => $state->id]);
            return '';
        }
        $lock = \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE)->get_lock('entry' . $entryid, 10);
        if (!$lock) {
            throw new \moodle_exception('push:locked', 'local_cpdlog');
        }
        try {
            return self::send_locked($entry, $state);
        } finally {
            $lock->release();
        }
    }

    /**
     * Sends an entry once its lock is held.
     *
     * @param entry $entry The entry.
     * @param \stdClass $state Its push state.
     * @return string The resulting status.
     * @throws \moodle_exception When iMIS fails and the task should retry.
     */
    protected static function send_locked(entry $entry, \stdClass $state): string {
        global $DB;
        $connection = connection::get();
        $user = $DB->get_record('user', ['id' => $entry->get('userid')], 'id, username, idnumber, email, deleted');
        $memberid = $user && !$user->deleted ? connection::member_id($user) : '';
        $action = self::action($entry, $state);

        // Reasons not to send that a retry would not fix.
        $skip = null;
        if (!self::is_enabled()) {
            // Switched off since it was queued: kept, to send again once it is back on.
            $skip = get_string('push:offnow', 'local_cpdlog');
        } else if ($action === 'reconcile') {
            $skip = get_string('push:nokey', 'local_cpdlog');
        } else if (!$connection->is_configured()) {
            $skip = get_string('imis:notconfigured', 'local_cpdlog');
        } else if ($missing = payload::get_missing()) {
            $skip = get_string('push:mappingincomplete', 'local_cpdlog', implode(', ', $missing));
        } else if ($memberid === '') {
            $skip = get_string('push:nomember', 'local_cpdlog');
        } else if ($action === 'reverse' && !$state->imiskey) {
            $skip = get_string('push:notsent', 'local_cpdlog');
        } else if ($entry->get('status') !== entry::STATUS_APPROVED && $action !== 'reverse') {
            $skip = get_string('push:notapproved', 'local_cpdlog');
        }
        if ($skip !== null) {
            return self::record($state, $entry, self::STATUS_SKIPPED, $action, null, $skip);
        }

        // An existing row is changed where it was written, even if the settings have changed since.
        $entity = $state->entity ?: payload::entity();
        try {
            $body = payload::build($entry, $memberid, $action === 'create' ? null : $state->imiskey, $entity);
        } catch (\moodle_exception $e) {
            return self::record($state, $entry, self::STATUS_SKIPPED, $action, null, $e->getMessage());
        }
        $json = json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (self::is_dry_run()) {
            return self::record($state, $entry, self::STATUS_DRYRUN, $action, $json, null);
        }

        try {
            $client = new client($connection);
            if ($action === 'create') {
                $reply = $client->send('POST', '/api/' . $entity, $body);
                $state->imiskey = payload::key_from_reply($reply) ?: null;
                // Kept at once, with the time, so a later failure or a resend never creates the row a
                // second time, even when iMIS did not say its key.
                $state->timesent = time();
                $DB->update_record(self::TABLE, (object) [
                    'id' => $state->id,
                    'imiskey' => $state->imiskey,
                    'entity' => $entity,
                    'timesent' => $state->timesent,
                ]);
                // A reversal made while the row was being created is applied now.
                $entry->read();
                if ($state->imiskey && $entry->get('status') === entry::STATUS_REVERSED) {
                    $action = 'reverse';
                    $body = payload::build($entry, $memberid, $state->imiskey, $entity);
                    $json = json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $client->send('PUT', self::row_path($entity, $state->imiskey), $body);
                }
            } else {
                $client->send('PUT', self::row_path($entity, (string) $state->imiskey), $body);
            }
        } catch (\moodle_exception $e) {
            $attempts = (int) $state->attempts + 1;
            $DB->set_field(self::TABLE, 'attempts', $attempts, ['id' => $state->id]);
            $state->attempts = $attempts;
            self::record($state, $entry, self::STATUS_FAILED, $action, $json, $e->getMessage());
            if ($attempts < self::MAX_ATTEMPTS) {
                throw $e;
            }
            return self::STATUS_FAILED;
        }
        $error = $state->imiskey ? null : get_string('push:nokey', 'local_cpdlog');
        return self::record($state, $entry, self::STATUS_SENT, $action, $json, $error, time());
    }

    /**
     * Returns the path of an existing row: /api/{object}/~{key parts}, or /api/Activity/{SEQN}.
     *
     * @param string $entity The business object.
     * @param string $key The row's key.
     * @return string
     * @throws \moodle_exception If the key is not usable.
     */
    protected static function row_path(string $entity, string $key): string {
        $parts = explode('|', $key);
        foreach ($parts as $part) {
            if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $part)) {
                throw new \moodle_exception('push:badkey', 'local_cpdlog');
            }
        }
        return $entity === 'Activity' && count($parts) === 1 ? '/api/Activity/' . $key : '/api/' . $entity . '/~' . $key;
    }

    /**
     * Records the outcome of a send on the push state and the entry.
     *
     * @param \stdClass $state The push state.
     * @param entry $entry The entry.
     * @param string $status The new status.
     * @param string $action What was or would be sent.
     * @param string|null $payload The body, or null to leave it unchanged.
     * @param string|null $error Why it failed or was skipped.
     * @param int|null $timesent When it was sent.
     * @return string The status.
     */
    protected static function record(
        \stdClass $state,
        entry $entry,
        string $status,
        string $action,
        ?string $payload,
        ?string $error,
        ?int $timesent = null
    ): string {
        global $DB;
        $record = (object) [
            'id' => $state->id,
            'status' => $status,
            'action' => $action,
            'error' => $error === null ? null : \core_text::substr($error, 0, 255),
            'timemodified' => time(),
        ];
        if ($payload !== null) {
            $record->payload = $payload;
        }
        if ($timesent !== null) {
            $record->timesent = $timesent;
            $record->attempts = 0;
        }
        $DB->update_record(self::TABLE, $record);
        $DB->set_field(entry::TABLE, 'syncstatus', $status, ['id' => $entry->get('id')]);
        return $status;
    }

    /**
     * Returns whether an iMIS row Moodle wrote matches a key from an import, for the import's loop guard.
     *
     * @param string $key The import row's key.
     * @param string $memberid The import row's member ID.
     * @return int|null The entry the row came from, or null if Moodle did not write it.
     */
    public static function find_pushed(string $key, string $memberid): ?int {
        global $DB;
        $keys = array_unique(array_filter([$key, $memberid !== '' ? $memberid . '|' . $key : '']));
        if (!$keys) {
            return null;
        }
        [$insql, $params] = $DB->get_in_or_equal($keys, SQL_PARAMS_NAMED);
        $entryid = $DB->get_field_select(self::TABLE, 'MIN(entryid)', "imiskey {$insql}", $params);
        return $entryid ? (int) $entryid : null;
    }
}
