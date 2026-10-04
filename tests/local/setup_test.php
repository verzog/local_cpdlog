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
 * Tests for the starting data.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local;

use local_cpdlog\persistent\category;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the starting data.
 */
#[CoversClass(setup::class)]
final class setup_test extends \advanced_testcase
{
    /**
     * Returns the category short names in list order.
     *
     * @return string[]
     */
    private static function shortnames(): array {
        return array_values(array_map(fn($category) => $category->get('shortname'), category::get_records([], 'sortorder')));
    }

    /**
     * Running the step again adds nothing and leaves staff changes alone.
     */
    public function test_add_default_categories_is_repeatable(): void {
        $this->resetAfterTest();
        $category = category::get_record(['shortname' => 'EA']);
        $category->set('name', 'Renamed by staff');
        $category->update();

        setup::add_default_categories();

        $this->assertSame(['EA', 'RP', 'MO'], self::shortnames());
        $this->assertSame('Renamed by staff', category::get_record(['shortname' => 'EA'])->get('name'));
    }

    /**
     * A site without the starting categories, such as one upgraded from an earlier version, gets them.
     */
    public function test_add_default_categories_fills_gaps(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->delete_records(category::TABLE);
        (new category(0, (object) ['name' => 'Clinical audit', 'shortname' => 'CA']))->create();

        setup::add_default_categories();

        $this->assertSame(['CA', 'EA', 'RP', 'MO'], self::shortnames());
    }
}
