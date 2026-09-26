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
 * Tests for working out a member's CPD progress.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\local;

use local_cpdlog\persistent\entry;
use local_cpdlog\persistent\period;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for working out a member's CPD progress against their targets.
 */
#[CoversClass(progress::class)]
#[CoversClass(display::class)]
final class progress_test extends \advanced_testcase
{
    /** @var \stdClass The member. */
    private \stdClass $member;

    /** @var period The 2026 period. */
    private period $period;

    /** @var \local_cpdlog_generator The plugin generator. */
    private \local_cpdlog_generator $generator;

    /**
     * Sets up a 2026 and a 2025 period, a member and three targets for 2026.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Australia/Sydney');
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_cpdlog');
        $this->period = $this->generator->create_period(['name' => '2026', 'firstday' => '01/01/2026', 'lastday' => '31/12/2026']);
        $this->generator->create_period(['name' => '2025', 'firstday' => '01/01/2025', 'lastday' => '31/12/2025']);
        $this->member = $this->getDataGenerator()->create_user();

        $this->generator->create_target(['period' => '2026', 'name' => 'Total', 'requiredhours' => 50, 'sortorder' => 1]);
        $this->generator->create_target([
            'period' => '2026',
            'name' => 'Reviewing and measuring',
            'requiredhours' => 5,
            'categories' => 'RP, MO',
            'sortorder' => 2,
        ]);
        $this->generator->create_target([
            'period' => '2026',
            'name' => 'Educational',
            'requiredhours' => 0.3,
            'categories' => 'EA',
            'sortorder' => 3,
        ]);
    }

    /**
     * Creates an entry for the member in 2026.
     *
     * @param string $category Category short name.
     * @param float $hours Hours.
     * @param string $status Entry status.
     * @param array $overrides Other fields.
     */
    private function entry(string $category, float $hours, string $status, array $overrides = []): void {
        $this->generator->create_entry($overrides + [
            'userid' => $this->member->id,
            'period' => '2026',
            'category' => $category,
            'hours' => $hours,
            'status' => $status,
        ]);
    }

    /**
     * Returns the member's 2026 progress keyed by target name.
     *
     * @return \stdClass[]
     */
    private function progress_by_name(): array {
        $rows = [];
        foreach (progress::get_progress((int) $this->member->id, $this->period->get('id')) as $row) {
            $rows[$row->target->get('name')] = $row;
        }
        return $rows;
    }

    /**
     * Only approved hours count; submitted hours are pending; other statuses, periods and members are ignored.
     */
    public function test_only_approved_hours_count(): void {
        $this->entry('EA', 0.1, entry::STATUS_APPROVED);
        $this->entry('EA', 0.2, entry::STATUS_APPROVED, ['source' => entry::SOURCE_IMIS]);
        $this->entry('RP', 3, entry::STATUS_APPROVED);
        $this->entry('MO', 1.5, entry::STATUS_SUBMITTED);
        $this->entry('MO', 4, entry::STATUS_DRAFT);
        $this->entry('MO', 8, entry::STATUS_REJECTED);
        $this->entry('RP', 16, entry::STATUS_REVERSED);
        $this->entry('RP', 32, entry::STATUS_APPROVED, ['period' => '2025', 'day' => '10/03/2025']);
        $this->entry('RP', 64, entry::STATUS_APPROVED, ['userid' => $this->getDataGenerator()->create_user()->id]);

        $rows = $this->progress_by_name();
        $this->assertSame(['Total', 'Reviewing and measuring', 'Educational'], array_keys($rows));

        $this->assertSame(3.3, $rows['Total']->approved);
        $this->assertSame(1.5, $rows['Total']->pending);
        $this->assertSame(50.0, $rows['Total']->required);
        $this->assertFalse($rows['Total']->met);
        $this->assertSame(6, $rows['Total']->percent);

        $this->assertSame(3.0, $rows['Reviewing and measuring']->approved);
        $this->assertSame(1.5, $rows['Reviewing and measuring']->pending);
        $this->assertSame(60, $rows['Reviewing and measuring']->percent);

        // 0.1 + 0.2 is exactly the 0.3 required, despite floating point.
        $this->assertSame(0.3, $rows['Educational']->approved);
        $this->assertSame(0.0, $rows['Educational']->pending);
        $this->assertTrue($rows['Educational']->met);
        $this->assertSame(100, $rows['Educational']->percent);
    }

    /**
     * Progress is capped at 100 percent, and a member's cohort adds its own targets.
     */
    public function test_cohort_targets_and_cap(): void {
        $cohort = $this->getDataGenerator()->create_cohort(['idnumber' => 'fellows']);
        cohort_add_member($cohort->id, $this->member->id);
        $this->generator->create_target([
            'period' => '2026',
            'cohort' => 'fellows',
            'name' => 'Fellows',
            'requiredhours' => 2,
        ]);
        $this->entry('RP', 9, entry::STATUS_APPROVED);

        $rows = $this->progress_by_name();
        $this->assertArrayHasKey('Fellows', $rows);
        $this->assertTrue($rows['Fellows']->met);
        $this->assertSame(100, $rows['Fellows']->percent);
        $this->assertTrue($rows['Reviewing and measuring']->met);
    }

    /**
     * The template context describes each target and the totals.
     */
    public function test_display_context(): void {
        $this->entry('RP', 3, entry::STATUS_APPROVED);
        $this->entry('MO', 1.5, entry::STATUS_SUBMITTED);

        $context = display::progress((int) $this->member->id, $this->period);

        $this->assertSame('Progress for 2026', $context['heading']);
        $this->assertSame('3.00 hours approved in this period, and 1.50 hours waiting for review.', $context['totals']);
        $this->assertTrue($context['hastargets']);
        $this->assertCount(3, $context['targets']);
        $this->assertSame('3.00 of 5.00 hours approved', $context['targets'][1]['summary']);
        $this->assertSame('1.50 more hours waiting for review', $context['targets'][1]['pending']);
        $this->assertSame('', $context['targets'][2]['pending']);

        $other = $this->generator->create_period(['name' => '2027', 'firstday' => '01/01/2027', 'lastday' => '31/12/2027']);
        $context = display::progress((int) $this->member->id, $other);
        $this->assertFalse($context['hastargets']);
        $this->assertSame('0.00 hours approved in this period.', $context['totals']);
    }
}
