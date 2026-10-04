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
 * Tests for the CPD category persistent.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\persistent;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the CPD category persistent.
 */
#[CoversClass(category::class)]
final class category_test extends \advanced_testcase
{
    /**
     * Installation adds the three starting categories in order.
     */
    public function test_install_adds_starting_categories(): void {
        $shortnames = array_map(fn($category) => $category->get('shortname'), category::get_records([], 'sortorder'));

        $this->assertSame(['EA', 'RP', 'MO'], array_values($shortnames));
    }

    /**
     * Short names must be unique, but a category may keep its own.
     */
    public function test_shortname_must_be_unique(): void {
        $this->resetAfterTest();

        $duplicate = new category(0, (object) ['name' => 'Duplicate', 'shortname' => 'EA']);
        $this->assertArrayHasKey('shortname', $duplicate->get_errors());

        $existing = category::get_record(['shortname' => 'EA']);
        $existing->set('name', 'Renamed');
        $this->assertTrue($existing->is_valid());
    }

    /**
     * Blank names and short names are rejected.
     */
    public function test_blank_fields_rejected(): void {
        $errors = (new category(0, (object) ['name' => '  ', 'shortname' => '']))->get_errors();

        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('shortname', $errors);
    }

    /**
     * Moving swaps neighbours, and moving past either end changes nothing.
     */
    public function test_move(): void {
        $this->resetAfterTest();
        $order = fn() => array_values(array_map(
            fn($category) => $category->get('shortname'),
            category::get_records([], 'sortorder')
        ));
        $id = fn(string $shortname) => (int) category::get_record(['shortname' => $shortname])->get('id');

        category::move($id('MO'), true);
        $this->assertSame(['EA', 'MO', 'RP'], $order());

        category::move($id('EA'), true);
        $this->assertSame(['EA', 'MO', 'RP'], $order());

        category::move($id('RP'), false);
        $this->assertSame(['EA', 'MO', 'RP'], $order());

        category::move($id('EA'), false);
        $this->assertSame(['MO', 'EA', 'RP'], $order());
    }

    /**
     * Moving records who changed the moved categories and when, and leaves the others alone.
     */
    public function test_move_updates_audit_fields(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $DB->set_field(category::TABLE, 'timemodified', 1000);
        $DB->set_field(category::TABLE, 'usermodified', 0);
        $id = fn(string $shortname) => (int) category::get_record(['shortname' => $shortname])->get('id');

        category::move($id('MO'), true);

        foreach (['RP', 'MO'] as $shortname) {
            $record = $DB->get_record(category::TABLE, ['shortname' => $shortname]);
            $this->assertGreaterThan(1000, (int) $record->timemodified, $shortname);
            $this->assertEquals(get_admin()->id, $record->usermodified, $shortname);
        }
        $unmoved = $DB->get_record(category::TABLE, ['shortname' => 'EA']);
        $this->assertEquals(1000, $unmoved->timemodified);
        $this->assertEquals(0, $unmoved->usermodified);
    }

    /**
     * New categories sort after existing ones.
     */
    public function test_next_sortorder(): void {
        $this->assertSame(3, category::next_sortorder());
    }
}
