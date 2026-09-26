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
 * CPD entry persistent.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\persistent;

use core\lang_string;

/**
 * One logged CPD activity.
 *
 * Record-level checks live here. Rules that depend on the member, such as enrolment, open periods
 * and who may change an entry, are enforced by \local_cpdlog\local\entry_manager.
 */
class entry extends \core\persistent
{
    /** @var string Database table. */
    const TABLE = 'local_cpdlog_entry';

    /** @var string A member's unsubmitted entry. */
    const STATUS_DRAFT = 'draft';

    /** @var string Waiting for staff review. */
    const STATUS_SUBMITTED = 'submitted';

    /** @var string Approved by staff; locked. */
    const STATUS_APPROVED = 'approved';

    /** @var string Rejected by staff with a reason; the member may revise it. */
    const STATUS_REJECTED = 'rejected';

    /** @var string An approved entry reversed by staff; kept for the record. */
    const STATUS_REVERSED = 'reversed';

    /** @var string[] Every entry status, in workflow order. */
    const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_REVERSED,
    ];

    /** @var string Created in Moodle, which owns it. */
    const SOURCE_MOODLE = 'moodle';

    /** @var string Imported from iMIS, which owns it; read-only in Moodle. */
    const SOURCE_IMIS = 'imis';

    /** @var float Largest value the hours column holds. */
    const MAX_HOURS = 9999.99;

    /**
     * Defines the properties of an entry.
     *
     * @return array
     */
    protected static function define_properties(): array {
        return [
            'userid' => ['type' => PARAM_INT],
            'categoryid' => ['type' => PARAM_INT],
            'periodid' => ['type' => PARAM_INT],
            'courseid' => ['type' => PARAM_INT, 'null' => NULL_ALLOWED, 'default' => null],
            'coursename' => ['type' => PARAM_TEXT, 'null' => NULL_ALLOWED, 'default' => null],
            'hours' => ['type' => PARAM_FLOAT],
            'activitydate' => ['type' => PARAM_INT],
            'description' => ['type' => PARAM_RAW, 'null' => NULL_ALLOWED, 'default' => ''],
            'descriptionformat' => ['type' => PARAM_INT, 'default' => FORMAT_HTML],
            'status' => [
                'type' => PARAM_ALPHA,
                'choices' => self::STATUSES,
                'default' => self::STATUS_DRAFT,
            ],
            'source' => [
                'type' => PARAM_ALPHA,
                'choices' => [self::SOURCE_MOODLE, self::SOURCE_IMIS],
                'default' => self::SOURCE_MOODLE,
            ],
            'externalref' => ['type' => PARAM_RAW, 'null' => NULL_ALLOWED, 'default' => null],
            'syncstatus' => ['type' => PARAM_ALPHA, 'null' => NULL_ALLOWED, 'default' => null],
            'timesubmitted' => ['type' => PARAM_INT, 'null' => NULL_ALLOWED, 'default' => null],
            'reviewedby' => ['type' => PARAM_INT, 'null' => NULL_ALLOWED, 'default' => null],
            'timereviewed' => ['type' => PARAM_INT, 'null' => NULL_ALLOWED, 'default' => null],
            'rejectionreason' => ['type' => PARAM_RAW, 'null' => NULL_ALLOWED, 'default' => null],
            'reversedby' => ['type' => PARAM_INT, 'null' => NULL_ALLOWED, 'default' => null],
            'timereversed' => ['type' => PARAM_INT, 'null' => NULL_ALLOWED, 'default' => null],
            'reversalreason' => ['type' => PARAM_RAW, 'null' => NULL_ALLOWED, 'default' => null],
        ];
    }

    /**
     * Validates that the hours are positive and fit the two-decimal column.
     *
     * The per-entry maximum set by staff is checked by the entry manager when a member saves.
     *
     * @param float $value The hours.
     * @return true|lang_string
     */
    protected function validate_hours($value) {
        $hours = (float) $value;
        if ($hours < 0.01 || $hours > self::MAX_HOURS || abs(round($hours, 2) - $hours) > 1e-9) {
            return new lang_string('error:entryhours', 'local_cpdlog');
        }
        return true;
    }

    /**
     * Validates that the category exists.
     *
     * @param int $value The category id.
     * @return true|lang_string
     */
    protected function validate_categoryid($value) {
        return category::record_exists($value) ? true : new lang_string('invalidrecord', 'error', category::TABLE);
    }

    /**
     * Validates that the period exists.
     *
     * @param int $value The period id.
     * @return true|lang_string
     */
    protected function validate_periodid($value) {
        return period::record_exists($value) ? true : new lang_string('invalidrecord', 'error', period::TABLE);
    }

    /**
     * Whether the entry was created in Moodle, so Moodle may change it.
     *
     * @return bool
     */
    public function is_moodle_owned(): bool {
        return $this->get('source') === self::SOURCE_MOODLE;
    }
}
