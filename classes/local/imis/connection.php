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
 * The iMIS connection the logbook uses.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local\imis;

/**
 * Where and how to reach iMIS, and who a member is there.
 *
 * When the Membership Bridge (local_membershipbridge) has its REST connection set, the logbook uses
 * that same connection and service account, since each iMIS API user is a paid licence. Otherwise it
 * uses its own settings.
 */
class connection
{
    /** @var string The Membership Bridge component. */
    const BRIDGE = 'local_membershipbridge';

    /** @var int Default seconds to wait for iMIS. */
    const DEFAULT_TIMEOUT = 10;

    /** @var string Base URL of the iMIS site, without /api or a trailing slash. */
    public string $baseurl = '';

    /** @var string Service account username. */
    public string $username = '';

    /** @var string Service account password. */
    public string $password = '';

    /** @var int Seconds to wait for iMIS. */
    public int $timeout = self::DEFAULT_TIMEOUT;

    /** @var bool Whether the settings are the Membership Bridge's. */
    public bool $frombridge = false;

    /**
     * Returns the connection in use: the Membership Bridge's when it is set, else the logbook's own.
     *
     * @return self
     */
    public static function get(): self {
        $connection = new self();
        if (self::bridge_installed() && trim((string) get_config(self::BRIDGE, 'rest_base_url')) !== '') {
            $connection->frombridge = true;
            $config = get_config(self::BRIDGE);
            $baseurl = $config->rest_base_url ?? '';
            $username = $config->rest_username ?? '';
            $password = $config->rest_password ?? '';
            $timeout = (int) ($config->ws_timeout ?? 0);
        } else {
            $config = get_config('local_cpdlog');
            $baseurl = $config->imisbaseurl ?? '';
            $username = $config->imisusername ?? '';
            $password = $config->imispassword ?? '';
            $timeout = 0;
        }
        $connection->baseurl = rtrim(trim((string) $baseurl), '/');
        $connection->username = trim((string) $username);
        $connection->password = (string) $password;
        // Pages wait on iMIS while the list loads, so the wait is kept short.
        $connection->timeout = $timeout > 0 ? min($timeout, self::DEFAULT_TIMEOUT) : self::DEFAULT_TIMEOUT;
        return $connection;
    }

    /**
     * Whether the Membership Bridge is installed.
     *
     * @return bool
     */
    public static function bridge_installed(): bool {
        return \core_component::get_component_directory(self::BRIDGE) !== null
            && class_exists('\local_membershipbridge\util');
    }

    /**
     * Whether everything needed to reach iMIS is set.
     *
     * @return bool
     */
    public function is_configured(): bool {
        return $this->baseurl !== '' && $this->username !== '' && $this->password !== '';
    }

    /**
     * Returns a key for this connection, so cached iMIS data from another site or account is never used.
     *
     * @return string
     */
    public function key(): string {
        return md5($this->baseurl . "\0" . $this->username);
    }

    /**
     * Returns a member's iMIS ID: as the Membership Bridge finds it when installed, else their ID number.
     *
     * @param \stdClass $user The member, with at least id, username, idnumber and email.
     * @return string The ID, or '' if they have none.
     */
    public static function member_id(\stdClass $user): string {
        if (self::bridge_installed()) {
            return \local_membershipbridge\util::get_imis_id($user);
        }
        $id = trim((string) ($user->idnumber ?? ''));
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) ? $id : '';
    }

    /**
     * Finds the Moodle user an iMIS ID belongs to: as the Membership Bridge finds them when installed,
     * else the one active user with that ID number.
     *
     * @param string $memberid The iMIS ID.
     * @return \stdClass|null The user (id), or null when none or more than one match.
     */
    public static function find_user(string $memberid): ?\stdClass {
        if ($memberid === '') {
            return null;
        }
        if (self::bridge_installed()) {
            $user = \local_membershipbridge\util::find_user_by_imis_id($memberid);
            return $user && empty($user->deleted) ? $user : null;
        }
        return self::find_user_by('idnumber', $memberid);
    }

    /**
     * Finds the one active user whose field matches exactly (email ignoring case).
     *
     * @param string $field idnumber, username or email.
     * @param string $value The value.
     * @return \stdClass|null The user (id), or null when none or more than one match.
     */
    public static function find_user_by(string $field, string $value): ?\stdClass {
        global $DB;
        if ($value === '' || !in_array($field, ['idnumber', 'username', 'email'], true)) {
            return null;
        }
        $where = $field === 'email' ? $DB->sql_equal('email', ':value', false) : "{$field} = :value";
        $users = $DB->get_records_select('user', "{$where} AND deleted = 0", ['value' => $value], 'id', 'id', 0, 2);
        return count($users) === 1 ? reset($users) : null;
    }
}
