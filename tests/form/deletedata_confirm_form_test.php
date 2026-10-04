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
 * Tests for confirming a deletion of a member's CPD data.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
