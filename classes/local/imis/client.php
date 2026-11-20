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
 * A small read-only client for the iMIS REST API.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local\imis;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Talks to the iMIS REST API, following the client in local_membershipbridge and wormiss.
 *
 * It signs in with the service account (POST /token, password grant), keeps the token until a
 * minute before it expires, asks for a new one once if iMIS answers 401, and reads every page of
 * a list. Only the push to iMIS (decision 26) writes, through send().
 */
class client
{
    /** @var int Rows asked for per page. */
    const PAGE_SIZE = 100;

    /** @var int The most rows read from one list. */
    const MAX_ROWS = 1000;

    /** @var connection The connection. */
    protected connection $connection;

    /** @var string The current token, or '' before signing in. */
    protected string $token = '';

    /** @var int When the token should be renewed. */
    protected int $tokenexpires = 0;

    /**
     * Creates a client for a connection.
     *
     * @param connection $connection The connection, which must be configured.
     */
    public function __construct(connection $connection) {
        if (!$connection->is_configured()) {
            throw new \moodle_exception('imis:notconfigured', 'local_cpdlog');
        }
        $this->connection = $connection;
    }

    /**
     * Reads one resource.
     *
     * @param string $path Path from the site root, such as /api/Event/E1.
     * @return \stdClass|null The decoded body, or null when iMIS answers 404.
     * @throws \moodle_exception On any other failure.
     */
    public function get(string $path): ?\stdClass {
        $response = $this->request('GET', $path, null, true);
        if ($response === null) {
            return null;
        }
        $body = json_decode((string) $response->getBody());
        if (!$body instanceof \stdClass) {
            throw new \moodle_exception('imis:unreadable', 'local_cpdlog');
        }
        return $body;
    }

    /**
     * Reads every page of a list, such as GET /api/Event.
     *
     * A page that is not a list stops the read with an error, so a failed read is never mistaken
     * for an empty one.
     *
     * @param string $path Path from the site root, with its own query string if any.
     * @param int $max The most rows to read.
     * @return \stdClass[] The rows.
     * @throws \moodle_exception On failure.
     */
    public function get_all(string $path, int $max = self::MAX_ROWS): array {
        $rows = [];
        $offset = 0;
        $separator = str_contains($path, '?') ? '&' : '?';
        do {
            $body = $this->get($path . $separator . 'limit=' . self::PAGE_SIZE . '&offset=' . $offset);
            if ($body === null || !isset($body->Items->{'$values'}) || !is_array($body->Items->{'$values'})) {
                throw new \moodle_exception('imis:unreadable', 'local_cpdlog');
            }
            $page = array_values(array_filter($body->Items->{'$values'}, fn($row) => $row instanceof \stdClass));
            array_push($rows, ...$page);
            $hasnext = (bool) self::value($body->HasNext ?? false);
            $offset = isset($body->NextOffset) ? (int) self::value($body->NextOffset) : $offset + count($page);
        } while ($hasnext && $page && count($rows) < $max);
        return array_slice($rows, 0, $max);
    }

    /**
     * Writes to iMIS: POST to create a row, PUT to change one. Used only by the push to iMIS.
     *
     * @param string $method POST or PUT.
     * @param string $path Path from the site root, such as /api/CPDLogbook.
     * @param array $body The body, sent as JSON.
     * @return \stdClass The decoded reply; empty when iMIS sends none.
     * @throws \moodle_exception On failure, including a 404.
     */
    public function send(string $method, string $path, array $body): \stdClass {
        if (!in_array($method, ['POST', 'PUT'], true)) {
            throw new \coding_exception('Only POST and PUT can be sent.');
        }
        $response = $this->request($method, $path, $body, true);
        if ($response === null) {
            throw new \moodle_exception('imis:failed', 'local_cpdlog', '', 404);
        }
        $body = json_decode((string) $response->getBody());
        return $body instanceof \stdClass ? $body : new \stdClass();
    }

    /**
     * Returns the properties of a generic iMIS row (GenericEntityData) as name => value.
     *
     * @param \stdClass $row The row.
     * @return array
     */
    public static function properties(\stdClass $row): array {
        $properties = [];
        foreach ($row->Properties->{'$values'} ?? [] as $property) {
            if ($property instanceof \stdClass && isset($property->Name)) {
                $properties[(string) $property->Name] = self::value($property->Value ?? null);
            }
        }
        return $properties;
    }

    /**
     * Unwraps an iMIS typed value ({"$type": ..., "$value": ...}).
     *
     * @param mixed $value The value.
     * @return mixed
     */
    public static function value($value) {
        if ($value instanceof \stdClass && property_exists($value, '$value')) {
            return $value->{'$value'};
        }
        return $value;
    }

    /**
     * Sends a request with the token, renewing it once if iMIS refuses it.
     *
     * @param string $method GET, POST or PUT.
     * @param string $path Path from the site root.
     * @param array|null $body A body to send as JSON, or null.
     * @param bool $retry Whether a 401 may be retried with a new token.
     * @return \Psr\Http\Message\ResponseInterface|null The response, or null for a 404.
     * @throws \moodle_exception On failure.
     */
    protected function request(string $method, string $path, ?array $body, bool $retry) {
        $options = ['headers' => ['Authorization' => 'Bearer ' . $this->token(), 'Accept' => 'application/json']];
        if ($body !== null) {
            $options['json'] = $body;
        }
        try {
            return $this->http()->request($method, $this->connection->baseurl . $path, $this->options($options));
        } catch (RequestException $e) {
            $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            if ($status === 404) {
                return null;
            }
            if ($status === 401 && $retry) {
                $this->token = '';
                return $this->request($method, $path, $body, false);
            }
            if ($method !== 'GET') {
                $reason = $e->getResponse() ? self::reason((string) $e->getResponse()->getBody()) : '';
                throw new \moodle_exception('imis:writefailed', 'local_cpdlog', '', trim($status . ' ' . $reason));
            }
            throw new \moodle_exception('imis:failed', 'local_cpdlog', '', $status ?: $e->getMessage());
        } catch (GuzzleException $e) {
            throw new \moodle_exception('imis:failed', 'local_cpdlog', '', $e->getMessage());
        }
    }

    /**
     * Returns iMIS's short explanation from an error reply: its message, or its validation errors.
     *
     * @param string $body The reply.
     * @return string Plain text, at most 200 characters; '' when there is none.
     */
    public static function reason(string $body): string {
        $data = json_decode($body);
        $parts = [];
        if ($data instanceof \stdClass) {
            foreach (['ExceptionMessage', 'Message', 'error_description'] as $field) {
                if (!empty($data->$field) && is_string($data->$field)) {
                    $parts[] = $data->$field;
                    break;
                }
            }
            foreach ($data->ValidationResults->Errors->{'$values'} ?? [] as $error) {
                if ($error instanceof \stdClass && !empty($error->Message)) {
                    $parts[] = (string) $error->Message;
                }
            }
        }
        $text = trim(preg_replace('/\s+/', ' ', strip_tags(implode(' ', $parts))));
        return \core_text::substr($text, 0, 200);
    }

    /**
     * Returns a token for the service account, signing in when there is none or it is about to expire.
     *
     * @return string
     * @throws \moodle_exception If iMIS refuses the service account.
     */
    protected function token(): string {
        if ($this->token !== '' && time() < $this->tokenexpires) {
            return $this->token;
        }
        try {
            $response = $this->http()->post($this->connection->baseurl . '/token', $this->options([
                'form_params' => [
                    'grant_type' => 'password',
                    'username' => $this->connection->username,
                    'password' => $this->connection->password,
                ],
            ]));
        } catch (GuzzleException $e) {
            $status = $e instanceof RequestException && $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            // The message could hold the request, so only the status is reported.
            throw new \moodle_exception('imis:signin', 'local_cpdlog', '', $status);
        }
        $body = json_decode((string) $response->getBody());
        if (!$body instanceof \stdClass || empty($body->access_token)) {
            throw new \moodle_exception('imis:signin', 'local_cpdlog', '', 0);
        }
        $this->token = (string) $body->access_token;
        $this->tokenexpires = time() + max(60, (int) ($body->expires_in ?? 3600)) - 60;
        return $this->token;
    }

    /**
     * Adds the timeouts and error handling every request uses.
     *
     * @param array $options Request options.
     * @return array
     */
    protected function options(array $options): array {
        return $options + [
            'timeout' => $this->connection->timeout,
            'connect_timeout' => $this->connection->timeout,
            'http_errors' => true,
        ];
    }

    /**
     * Returns Moodle's HTTP client, which applies the site's security and proxy settings.
     *
     * @return \core\http_client
     */
    protected function http(): \core\http_client {
        return \core\di::get(\core\http_client::class);
    }
}
