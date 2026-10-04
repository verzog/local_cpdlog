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
 * Tests for confirming a deletion of a member's CPD data.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\form;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for confirming a deletion of a member's CPD data.
 */
#[CoversClass(deletedata_confirm_form::class)]
final class deletedata_confirm_form_test extends \advanced_testcase
{
    /**
     * An active account is confirmed by its username, a deleted one by its user ID.
     */
    public function test_validation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $active = $this->getDataGenerator()->create_user(['username' => 'member1']);
        $deleted = $this->getDataGenerator()->create_user(['username' => 'gone1', 'deleted' => 1]);

        $form = new deletedata_confirm_form(null, ['user' => $active]);
        $this->assertSame([], $form->validation(['confirmusername' => ' member1 '], []));
        $this->assertArrayHasKey('confirmusername', $form->validation(['confirmusername' => (string) $active->id], []));

        $form = new deletedata_confirm_form(null, ['user' => $deleted]);
        $this->assertSame([], $form->validation(['confirmusername' => (string) $deleted->id], []));
        $this->assertArrayHasKey('confirmusername', $form->validation(['confirmusername' => 'gone1'], []));
        $this->assertArrayHasKey('confirmusername', $form->validation(['confirmusername' => $deleted->username], []));
    }
}
