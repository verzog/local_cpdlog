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
 * Tests for finding the member whose CPD data is to be deleted.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\form;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for finding the member whose CPD data is to be deleted.
 */
#[CoversClass(deletedata_find_form::class)]
final class deletedata_find_form_test extends \advanced_testcase
{
    /**
     * A username or email address finds exactly one active account; anything ambiguous finds none.
     */
    public function test_find_user(): void {
        $this->resetAfterTest();
        $member = $this->getDataGenerator()->create_user(['username' => 'member1', 'email' => 'member1@example.com']);
        $this->getDataGenerator()->create_user(['username' => 'shared1', 'email' => 'shared@example.com']);
        $this->getDataGenerator()->create_user(['username' => 'shared2', 'email' => 'shared@example.com']);
        $this->getDataGenerator()->create_user(['username' => 'gone1', 'email' => 'gone1@example.com', 'deleted' => 1]);

        $this->assertEquals($member->id, deletedata_find_form::find_user('member1')->id);
        $this->assertEquals($member->id, deletedata_find_form::find_user('Member1')->id);
        $this->assertEquals($member->id, deletedata_find_form::find_user('MEMBER1@example.com')->id);
        $this->assertNull(deletedata_find_form::find_user('shared@example.com'));
        $this->assertNull(deletedata_find_form::find_user('gone1'));
        $this->assertNull(deletedata_find_form::find_user('nobody'));
        $this->assertNull(deletedata_find_form::find_user(''));
        $this->assertNull(deletedata_find_form::find_user('guest'));
    }

    /**
     * An identifier that is one account's username and another account's email address finds neither.
     */
    public function test_find_user_username_matches_other_email(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_user(['username' => 'jo@example.com', 'email' => 'jo.one@example.com']);
        $this->getDataGenerator()->create_user(['username' => 'jo2', 'email' => 'jo@example.com']);

        $this->assertNull(deletedata_find_form::find_user('jo@example.com'));
    }

    /**
     * An account whose username equals its own email address is still found.
     */
    public function test_find_user_username_is_own_email(): void {
        $this->resetAfterTest();
        $member = $this->getDataGenerator()->create_user(['username' => 'sam@example.com', 'email' => 'sam@example.com']);

        $this->assertEquals($member->id, deletedata_find_form::find_user('sam@example.com')->id);
    }

    /**
     * A deleted account is found by its original username, original email address or user ID.
     */
    public function test_find_deleted_user(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $active = $generator->create_user(['username' => 'active1', 'email' => 'active1@example.com']);
        $gone = $generator->create_user(['username' => 'gone1', 'email' => 'Gone.One@example.com']);
        $generator->get_plugin_generator('local_cpdlog')->create_deleted_account(['user' => 'gone1']);
        // Another deleted account whose old email address starts like gone1's username.
        $generator->create_user(['username' => 'other1', 'email' => 'gone1.x@example.com', 'deleted' => 1]);

        $this->assertEquals($gone->id, deletedata_find_form::find_user('gone1', true)->id);
        $this->assertEquals($gone->id, deletedata_find_form::find_user('GONE1', true)->id);
        $this->assertEquals($gone->id, deletedata_find_form::find_user('gone.one@example.com', true)->id);
        $this->assertEquals($gone->id, deletedata_find_form::find_user((string) $gone->id, true)->id);
        $this->assertNull(deletedata_find_form::find_user('gone1'));
        $this->assertNull(deletedata_find_form::find_user('active1', true));
        $this->assertNull(deletedata_find_form::find_user('active1@example.com', true));
        $this->assertNull(deletedata_find_form::find_user((string) $active->id, true));
        $this->assertNull(deletedata_find_form::find_user('gone', true));
        $this->assertNull(deletedata_find_form::find_user('', true));
    }

    /**
     * Several deleted accounts with the same old email address are not found by it, only by user ID.
     */
    public function test_find_deleted_user_ambiguous(): void {
        $this->resetAfterTest();
        $first = $this->getDataGenerator()->create_user(['username' => 'twin1', 'email' => 'twin@example.com', 'deleted' => 1]);
        $second = $this->getDataGenerator()->create_user(['username' => 'twin2', 'email' => 'twin@example.com', 'deleted' => 1]);

        $this->assertNull(deletedata_find_form::find_user('twin@example.com', true));
        $this->assertEquals($first->id, deletedata_find_form::find_user('twin1', true)->id);
        $this->assertEquals($second->id, deletedata_find_form::find_user((string) $second->id, true)->id);
    }
}
