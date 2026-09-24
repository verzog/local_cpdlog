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
 * Tests for the CPD category persistent.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
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
     * New categories sort after existing ones.
     */
    public function test_next_sortorder(): void {
        $this->assertSame(3, category::next_sortorder());
    }
}
