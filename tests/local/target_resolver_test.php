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
 * Tests for working out which targets apply and which members have cohort conflicts.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\local;

use local_cpdlog\persistent\target;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for working out which targets apply and which members have cohort conflicts.
 */
#[CoversClass(target_resolver::class)]
final class target_resolver_test extends \advanced_testcase
{
    /** @var int The 2026 period. */
    private int $periodid;

    /** @var \stdClass[] Cohorts keyed by idnumber: FEL and REG have targets, NOT has none. */
    private array $cohorts = [];

    /**
     * Creates a period with an all-members target and targets for two of three cohorts.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();

        $generator = $this->getDataGenerator()->get_plugin_generator('local_cpdlog');
        $this->periodid = (int) $generator->create_period([
            'name' => '2026',
            'firstday' => '01/01/2026',
            'lastday' => '31/12/2026',
        ])->get('id');
        foreach (['FEL' => 'Fellows', 'REG' => 'Registrars', 'NOT' => 'No targets'] as $idnumber => $name) {
            $this->cohorts[$idnumber] = $this->getDataGenerator()->create_cohort(['idnumber' => $idnumber, 'name' => $name]);
        }
        $period = ['periodid' => $this->periodid];
        $generator->create_target($period + ['name' => 'Total', 'requiredhours' => 50]);
        $generator->create_target($period + ['cohort' => 'FEL', 'name' => 'Fellows', 'requiredhours' => 10]);
        $generator->create_target($period + ['cohort' => 'REG', 'name' => 'Registrars', 'requiredhours' => 20]);
    }

    /**
     * Creates a member in the given cohorts.
     *
     * @param string[] $idnumbers Cohort idnumbers.
     * @return int The user id.
     */
    private function create_member(array $idnumbers): int {
        $user = $this->getDataGenerator()->create_user();
        foreach ($idnumbers as $idnumber) {
            cohort_add_member($this->cohorts[$idnumber]->id, $user->id);
        }
        return (int) $user->id;
    }

    /**
     * Returns the names of the targets that apply to a member.
     *
     * @param int $userid The member.
     * @return string[]
     */
    private function applicable(int $userid): array {
        $targets = target_resolver::get_applicable_targets($userid, $this->periodid);
        return array_map(fn(target $target) => $target->get('name'), $targets);
    }

    /**
     * A member in no cohort, or only in a cohort without targets, gets the all-members targets.
     */
    public function test_all_members_targets_apply_to_everyone(): void {
        $this->assertSame(['Total'], $this->applicable($this->create_member([])));
        $this->assertSame(['Total'], $this->applicable($this->create_member(['NOT'])));
    }

    /**
     * A cohort's targets are added to the all-members targets for its members.
     */
    public function test_cohort_targets_add_on(): void {
        $this->assertSame(['Total', 'Fellows'], $this->applicable($this->create_member(['FEL'])));
        $this->assertSame(['Total', 'Registrars'], $this->applicable($this->create_member(['REG', 'NOT'])));
    }

    /**
     * Two cohorts with targets are a conflict; until it is resolved only the all-members targets apply.
     */
    public function test_unresolved_conflict(): void {
        $userid = $this->create_member(['FEL', 'REG']);
        $this->create_member(['FEL', 'NOT']);

        $this->assertSame(['Total'], $this->applicable($userid));
        $conflicts = target_resolver::get_conflicts($this->periodid);
        $this->assertSame([$userid], array_keys($conflicts));
        $this->assertFalse($conflicts[$userid]->resolved);
        $this->assertEqualsCanonicalizing(
            [(int) $this->cohorts['FEL']->id, (int) $this->cohorts['REG']->id],
            $conflicts[$userid]->cohortids
        );
    }

    /**
     * Choosing a cohort adds its targets and resolves the conflict; choosing none keeps all-members only.
     */
    public function test_choice_resolves_conflict(): void {
        global $DB;
        $userid = $this->create_member(['FEL', 'REG']);
        $admin = (int) get_admin()->id;

        target_resolver::set_choice($userid, $this->periodid, (int) $this->cohorts['REG']->id, $admin);
        $this->assertSame(['Total', 'Registrars'], $this->applicable($userid));
        $conflict = target_resolver::get_conflicts($this->periodid)[$userid];
        $this->assertTrue($conflict->resolved);
        $this->assertEquals($admin, $conflict->choice->chosenby);

        target_resolver::set_choice($userid, $this->periodid, null, $admin);
        $this->assertSame(['Total'], $this->applicable($userid));
        $this->assertTrue(target_resolver::get_conflicts($this->periodid)[$userid]->resolved);
        $this->assertSame(1, $DB->count_records(target_resolver::CHOICE_TABLE));
    }

    /**
     * A choice for a cohort the member has left is ignored.
     */
    public function test_stale_choice_is_ignored(): void {
        global $DB;
        $third = $this->getDataGenerator()->create_cohort(['idnumber' => 'THI', 'name' => 'Third']);
        $this->getDataGenerator()->get_plugin_generator('local_cpdlog')->create_target(
            ['periodid' => $this->periodid, 'cohort' => 'THI', 'name' => 'Third', 'requiredhours' => 5]
        );
        $this->cohorts['THI'] = $third;
        $userid = $this->create_member(['FEL', 'REG', 'THI']);
        target_resolver::set_choice($userid, $this->periodid, (int) $this->cohorts['REG']->id, (int) get_admin()->id);

        // Still in two cohorts with targets: the conflict is open again.
        cohort_remove_member($this->cohorts['REG']->id, $userid);
        $this->assertSame(['Total'], $this->applicable($userid));
        $this->assertFalse(target_resolver::get_conflicts($this->periodid)[$userid]->resolved);

        // Down to one cohort with targets: no conflict, and that cohort's targets apply.
        cohort_remove_member($this->cohorts['THI']->id, $userid);
        $this->assertSame(['Total', 'Fellows'], $this->applicable($userid));
        $this->assertArrayNotHasKey($userid, target_resolver::get_conflicts($this->periodid));
        $this->assertTrue($DB->record_exists(target_resolver::CHOICE_TABLE, ['userid' => $userid]));
    }

    /**
     * Deleted users and targets in other periods are not conflicts.
     */
    public function test_conflicts_ignore_deleted_users_and_other_periods(): void {
        $deleted = $this->create_member(['FEL', 'REG']);
        delete_user(\core_user::get_user($deleted));

        $generator = $this->getDataGenerator()->get_plugin_generator('local_cpdlog');
        $other = $generator->create_period(['name' => '2027', 'firstday' => '01/01/2027', 'lastday' => '31/12/2027']);
        $generator->create_target(['periodid' => $other->get('id'), 'cohort' => 'NOT', 'name' => 'Other', 'requiredhours' => 1]);
        $userid = $this->create_member(['FEL', 'NOT']);

        $this->assertSame([], target_resolver::get_conflicts($this->periodid));
        $this->assertSame(['Total', 'Fellows'], $this->applicable($userid));
    }

    /**
     * Choices are only accepted for members with a conflict, and only for one of their cohorts.
     */
    public function test_set_choice_validation(): void {
        $admin = (int) get_admin()->id;
        $single = $this->create_member(['FEL']);
        $conflicted = $this->create_member(['FEL', 'REG']);

        try {
            target_resolver::set_choice($single, $this->periodid, null, $admin);
            $this->fail('Expected an exception for a member without a conflict.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertNull(target_resolver::get_choice($single, $this->periodid));
        }

        $this->expectException(\invalid_parameter_exception::class);
        target_resolver::set_choice($conflicted, $this->periodid, (int) $this->cohorts['NOT']->id, $admin);
    }
}
