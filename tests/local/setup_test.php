<?php
// Copyright (c) Skin Cancer College Australasia.
// All rights reserved.
//
// This file is part of a proprietary plugin developed by Skin Cancer
// College Australasia for use with Moodle. It is NOT free software and is
// NOT released under the GNU General Public License.
//
// Unauthorised copying, distribution, modification, or use of this file,
// in whole or in part, via any medium, is strictly prohibited without the
// prior written permission of Skin Cancer College Australasia. The software
// is provided "as is", without warranty of any kind, express or implied.
/**
 * Tests for the starting data.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
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
