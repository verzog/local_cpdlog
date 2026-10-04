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
 * Tests for finding the member whose CPD data is to be deleted.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
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
}
