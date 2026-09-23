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
 * Tests for the CPD logbook capability definitions.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
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
        $this->assertFalse(has_capability('local/cpdlog:manageperiods', $context, $user));
        $this->assertFalse(has_capability('local/cpdlog:sync', $context, $user));
    }
}
