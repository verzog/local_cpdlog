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
 * Tests for auditing CPD history from an iMIS IQA, and the nightly import.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local\import;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use local_cpdlog\persistent\entry;
use local_cpdlog\task\import_nightly;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\RequestInterface;

/**
 * Tests for the iMIS IQA source and the nightly import, against a fake iMIS.
 */
#[CoversClass(imis_iqa_source::class)]
#[CoversClass(import_nightly::class)]
final class imis_import_test extends \advanced_testcase
{
    /** @var array Replies by path prefix. */
    private array $replies = [];

    /** @var string[] Requests made. */
    private array $requests = [];

    /**
     * Sets up the connection, the IQA, the mapping, a 2026 period, a member and the fake iMIS.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Australia/Sydney');
        set_config('imisbaseurl', 'https://imis.example.org', 'local_cpdlog');
        set_config('imisusername', 'moodle-service', 'local_cpdlog');
        set_config('imispassword', 'secret', 'local_cpdlog');
        set_config('importiqa', '$/_SCCA/CPD/History', 'local_cpdlog');
        set_config('importmapmember', 'ID', 'local_cpdlog');
        set_config('importmapkey', 'Ordinal', 'local_cpdlog');
        set_config('importmapdate', 'ActivityDate', 'local_cpdlog');
        set_config('importmaphours', "EA=EducationPoints\nMO=MeasuringOutcomesPoints", 'local_cpdlog');
        $this->getDataGenerator()->get_plugin_generator('local_cpdlog')
            ->create_period(['name' => '2026', 'firstday' => '01/01/2026', 'lastday' => '31/12/2026']);
        $this->getDataGenerator()->create_user(['idnumber' => '1001']);

        $handler = function (RequestInterface $request) {
            $uri = $request->getUri();
            $target = $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');
            $this->requests[] = $request->getMethod() . ' ' . $target;
            if ($target === '/token') {
                return Create::promiseFor(new Response(200, [], json_encode(['access_token' => 't', 'expires_in' => 3600])));
            }
            foreach ($this->replies as $prefix => $reply) {
                if (str_starts_with($target, $prefix)) {
                    return Create::promiseFor($reply);
                }
            }
            return Create::promiseFor(new Response(404));
        };
        \core\di::set(\core\http_client::class, new \core\http_client(['handler' => HandlerStack::create($handler)]));
    }

    /**
     * Returns an IQA row (GenericEntityData).
     *
     * @param array $values Column => value.
     * @return array
     */
    private static function row(array $values): array {
        $props = [];
        foreach ($values as $name => $value) {
            $props[] = ['Name' => $name, 'Value' => is_float($value) ? ['$type' => 'System.Decimal', '$value' => $value] : $value];
        }
        return ['Properties' => ['$values' => $props]];
    }

    /**
     * Sets the IQA's reply.
     *
     * @param array[] $rows Rows of column => value.
     */
    private function iqa(array $rows): void {
        $this->replies['/api/iqa?QueryName=%24%2F_SCCA%2FCPD%2FHistory'] = new Response(200, [], json_encode([
            'Items' => ['$values' => array_map([self::class, 'row'], $rows)],
            'HasNext' => false,
        ]));
    }

    /**
     * The IQA's rows are read and audited; one member's ID goes to its first prompt; the fields
     * helper lists the IQA's columns and a business object's fields.
     */
    public function test_iqa_source(): void {
        $this->iqa([
            ['ID' => '1001', 'Ordinal' => '7', 'ActivityDate' => '2026-03-10T00:00:00', 'EducationPoints' => 1.5,
                'MeasuringOutcomesPoints' => 0.5],
        ]);
        $this->replies['/api/metadata/CPDLogbook'] = new Response(200, [], json_encode([
            'Properties' => ['$values' => [['Name' => 'ID'], ['Name' => 'Ordinal'], ['Name' => 'Hours']]],
        ]));
        $source = new imis_iqa_source();
        $this->assertTrue($source->is_configured());
        $this->assertSame(['ID', 'Ordinal', 'ActivityDate', 'EducationPoints', 'MeasuringOutcomesPoints'], $source->get_fields());
        $this->assertSame(['ID', 'Ordinal', 'Hours'], $source->get_object_fields('CPDLogbook'));

        $run = auditor::run($source, '1001', 2);
        $this->assertSame('imis', $run->source);
        $this->assertSame(2, json_decode($run->counts, true)['new']);
        $query = 'GET /api/iqa?QueryName=%24%2F_SCCA%2FCPD%2FHistory&Parameter=1001&limit=100&offset=0';
        $this->assertContains($query, $this->requests);
        // It only reads.
        $this->assertSame([], preg_grep('~^(PUT|DELETE|POST /api)~', $this->requests));

        $this->expectException(\moodle_exception::class);
        $source->get_object_fields('Bad name; drop');
    }

    /**
     * Without the IQA or the connection, the source cannot be used.
     */
    public function test_not_configured(): void {
        set_config('importiqa', '', 'local_cpdlog');
        $source = new imis_iqa_source();
        $this->assertFalse($source->is_configured());
        $this->expectException(\moodle_exception::class);
        $source->get_rows();
    }

    /**
     * The nightly task does nothing until switched on, only audits in a dry run, and imports into
     * the logbook once the dry run is off.
     */
    public function test_nightly(): void {
        $this->iqa([
            ['ID' => '1001', 'Ordinal' => '7', 'ActivityDate' => '2026-03-10', 'EducationPoints' => 2.0],
        ]);
        $task = new import_nightly();
        ob_start();
        $task->execute();
        $this->assertSame('', ob_get_clean());
        $this->assertSame([], $this->requests);

        set_config('importnightly', 1, 'local_cpdlog');
        ob_start();
        $task->execute();
        $output = ob_get_clean();
        $this->assertStringContainsString('Audit done: 1 new', $output);
        $this->assertStringContainsString('Dry run', $output);
        $this->assertFalse(entry::record_exists_select('source = :source', ['source' => entry::SOURCE_IMIS]));

        set_config('importdryrun', 0, 'local_cpdlog');
        ob_start();
        $task->execute();
        $this->assertStringContainsString('CPD history records imported: 1.', ob_get_clean());
        $entry = entry::get_record(['source' => entry::SOURCE_IMIS, 'externalref' => '7/EA']);
        $this->assertSame(entry::STATUS_APPROVED, $entry->get('status'));
        $this->assertSame('iMIS', $entry->get('provider'));
        $this->assertNull($entry->get('reviewedby'));
    }
}
