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
 * Tests for the CPD logbook capability definitions.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Tests for the CPD logbook capability definitions in db/access.php.
 */
#[CoversNothing]
final class access_test extends \advanced_testcase
{
    /**
     * Each capability is installed at system context with the expected risks.
     */
    public function test_capabilities_installed_with_risks(): void {
        $expected = [
            'local/cpdlog:approve' => RISK_PERSONAL | RISK_DATALOSS,
            'local/cpdlog:deletedata' => RISK_PERSONAL | RISK_DATALOSS,
            'local/cpdlog:manageperiods' => RISK_CONFIG,
            'local/cpdlog:submit' => RISK_SPAM,
            'local/cpdlog:sync' => RISK_CONFIG,
            'local/cpdlog:viewall' => RISK_PERSONAL,
            'local/cpdlog:viewown' => 0,
        ];

        foreach ($expected as $capability => $risks) {
            $info = get_capability_info($capability);
            $this->assertNotEmpty($info, $capability);
            $this->assertEquals(CONTEXT_SYSTEM, $info->contextlevel, $capability);
            $this->assertEquals($risks, $info->riskbitmask, $capability);
        }
    }

    /**
     * An ordinary member can log and view their own CPD but not act as staff.
     */
    public function test_member_defaults(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $context = \context_system::instance();

        $this->assertTrue(has_capability('local/cpdlog:submit', $context, $user));
        $this->assertTrue(has_capability('local/cpdlog:viewown', $context, $user));
        $this->assertFalse(has_capability('local/cpdlog:viewall', $context, $user));
        $this->assertFalse(has_capability('local/cpdlog:approve', $context, $user));
        $this->assertFalse(has_capability('local/cpdlog:deletedata', $context, $user));
        $this->assertFalse(has_capability('local/cpdlog:manageperiods', $context, $user));
        $this->assertFalse(has_capability('local/cpdlog:sync', $context, $user));
    }

    /**
     * No role can delete CPD data by default, not even managers.
     */
    public function test_deletedata_has_no_default_roles(): void {
        global $DB;
        $this->resetAfterTest();
        $manager = $this->getDataGenerator()->create_user();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        $this->getDataGenerator()->role_assign($roleid, $manager->id);

        $this->assertFalse(has_capability('local/cpdlog:deletedata', \context_system::instance(), $manager));
        $this->assertArrayNotHasKey('local/cpdlog:deletedata', get_default_capabilities('manager'));
    }
}
