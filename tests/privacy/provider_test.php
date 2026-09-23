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
 * Tests for the CPD logbook privacy provider.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\privacy;

use core_privacy\local\metadata\collection;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the CPD logbook privacy provider.
 */
#[CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase
{
    /**
     * Every table holding personal data is declared.
     */
    public function test_get_metadata_declares_tables(): void {
        $collection = provider::get_metadata(new collection('local_cpdlog'));
        $names = array_map(fn($item) => $item->get_name(), $collection->get_collection());

        $this->assertEqualsCanonicalizing([
            'local_cpdlog_category',
            'local_cpdlog_cohortchoice',
            'local_cpdlog_entry',
            'local_cpdlog_period',
            'local_cpdlog_target',
        ], $names);
    }

    /**
     * Every declared table and field has a language string.
     */
    public function test_get_metadata_strings_exist(): void {
        $manager = get_string_manager();
        foreach (provider::get_metadata(new collection('local_cpdlog'))->get_collection() as $item) {
            $this->assertTrue($manager->string_exists($item->get_summary(), 'local_cpdlog'), $item->get_summary());
            foreach ($item->get_privacy_fields() as $identifier) {
                $this->assertTrue($manager->string_exists($identifier, 'local_cpdlog'), $identifier);
            }
        }
    }

    /**
     * Every field declared for the entry table exists in the installed schema.
     */
    public function test_get_metadata_fields_exist(): void {
        global $DB;

        foreach (provider::get_metadata(new collection('local_cpdlog'))->get_collection() as $item) {
            $columns = $DB->get_columns($item->get_name());
            foreach (array_keys($item->get_privacy_fields()) as $field) {
                $this->assertArrayHasKey($field, $columns, $item->get_name() . '.' . $field);
            }
        }
    }
}
