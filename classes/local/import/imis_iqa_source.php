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
 * CPD history from an iMIS IQA.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local\import;

use local_cpdlog\local\imis\client;
use local_cpdlog\local\imis\connection;

/**
 * CPD history read from an IQA in iMIS, through the REST API. It only reads.
 *
 * The IQA is set in the import settings and must have "Available via REST API" ticked. Its first
 * prompt, if it has one, should be an optional member ID, so one member can be audited on their own.
 */
class imis_iqa_source implements source
{
    /** @var connection The iMIS connection. */
    protected connection $connection;

    /** @var string The IQA's path, such as $/_SCCA/CPD/History. */
    protected string $iqa;

    /**
     * Creates the source.
     *
     * @param connection|null $connection The connection; the one in use if null.
     * @param string|null $iqa The IQA path; the import setting if null.
     */
    public function __construct(?connection $connection = null, ?string $iqa = null) {
        $this->connection = $connection ?? connection::get();
        $this->iqa = trim($iqa ?? (string) get_config('local_cpdlog', 'importiqa'));
    }

    /**
     * Whether the connection and the IQA are both set.
     *
     * @return bool
     */
    public function is_configured(): bool {
        return $this->connection->is_configured() && $this->iqa !== '';
    }

    /**
     * Returns the source's name.
     *
     * @return string
     */
    public function get_name(): string {
        return 'imis';
    }

    /**
     * Returns the IQA's column names, from its first row.
     *
     * @return string[]
     * @throws \moodle_exception If iMIS cannot be read.
     */
    public function get_fields(): array {
        $body = $this->client()->get($this->path('') . '&limit=1');
        $rows = $body->Items->{'$values'} ?? [];
        return $rows && $rows[0] instanceof \stdClass ? array_keys(client::properties($rows[0])) : [];
    }

    /**
     * Returns the fields of an iMIS business object, such as CPDLogbook, from /api/metadata.
     *
     * @param string $object The business object's name.
     * @return string[]
     * @throws \moodle_exception If iMIS cannot be read, or the name is not valid.
     */
    public function get_object_fields(string $object): array {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $object)) {
            throw new \moodle_exception('import:badobject', 'local_cpdlog');
        }
        $body = $this->client()->get('/api/metadata/' . $object);
        $fields = [];
        foreach ($body->Properties->{'$values'} ?? [] as $property) {
            if ($property instanceof \stdClass && isset($property->Name)) {
                $fields[] = (string) $property->Name;
            }
        }
        return $fields;
    }

    /**
     * Returns the IQA's rows, every page.
     *
     * @param string $memberid Passed as the IQA's first prompt when set.
     * @return array[]
     * @throws \moodle_exception If iMIS cannot be read.
     */
    public function get_rows(string $memberid = ''): array {
        $rows = [];
        foreach ($this->client()->get_all($this->path($memberid), PHP_INT_MAX) as $row) {
            $rows[] = client::properties($row);
        }
        return $rows;
    }

    /**
     * Returns the request path for the IQA.
     *
     * @param string $memberid The member ID prompt, or ''.
     * @return string
     */
    protected function path(string $memberid): string {
        if (!$this->is_configured()) {
            throw new \moodle_exception('import:notconfigured', 'local_cpdlog');
        }
        $path = '/api/iqa?QueryName=' . rawurlencode($this->iqa);
        return $memberid === '' ? $path : $path . '&Parameter=' . rawurlencode($memberid);
    }

    /**
     * Returns a client for the connection.
     *
     * @return client
     */
    protected function client(): client {
        return new client($this->connection);
    }
}
