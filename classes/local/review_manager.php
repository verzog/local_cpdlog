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
 * Rules for staff approving, rejecting and reversing CPD entries.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\local;

use local_cpdlog\event\entry_approved;
use local_cpdlog\event\entry_rejected;
use local_cpdlog\event\entry_reversed;
use local_cpdlog\persistent\entry;
use local_cpdlog\persistent\period;

/**
 * Rules for staff approving, rejecting and reversing CPD entries.
 *
 * Callers must still check login, local/cpdlog:approve and the session key; this class checks the
 * entry's state and that reviewers never review or reverse their own entries.
 */
final class review_manager
{
    /** @var int Most entries approved in one bulk action, and the approval queue's page size. */
    const BULK_LIMIT = 50;

    /** @var int Seconds to wait for another reviewer to finish with the same entry. */
    const LOCK_TIMEOUT = 5;

    /**
     * Whether a reviewer may approve or reject an entry: submitted in Moodle, in an open period, and
     * not the reviewer's own.
     *
     * @param entry $entry The entry.
     * @param int $reviewerid The staff member.
     * @return bool
     */
    public static function can_review(entry $entry, int $reviewerid): bool {
        return $entry->get('status') === entry::STATUS_SUBMITTED
            && $entry->is_moodle_owned()
            && (int) $entry->get('userid') !== $reviewerid
            && !(new period($entry->get('periodid')))->is_closed();
    }

    /**
     * Whether a reviewer may reverse an entry: approved in Moodle, in an open period, and not the
     * reviewer's own. Reversal is final.
     *
     * @param entry $entry The entry.
     * @param int $reviewerid The staff member.
     * @return bool
     */
    public static function can_reverse(entry $entry, int $reviewerid): bool {
        return $entry->get('status') === entry::STATUS_APPROVED
            && $entry->is_moodle_owned()
            && (int) $entry->get('userid') !== $reviewerid
            && !(new period($entry->get('periodid')))->is_closed();
    }

    /**
     * Approves a submitted entry and notifies the member.
     *
     * @param entry $entry The entry.
     * @param int $reviewerid The staff member.
     * @throws \moodle_exception If the reviewer may not review the entry.
     */
    public static function approve(entry $entry, int $reviewerid): void {
        self::review($entry, $reviewerid, entry::STATUS_APPROVED);
    }

    /**
     * Rejects a submitted entry with a reason and notifies the member, who can then edit and resubmit it.
     *
     * @param entry $entry The entry.
     * @param int $reviewerid The staff member.
     * @param string $reason Why the entry was rejected; required.
     * @throws \moodle_exception If the reviewer may not review the entry or no reason is given.
     */
    public static function reject(entry $entry, int $reviewerid, string $reason): void {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \moodle_exception('error:reasonrequired', 'local_cpdlog');
        }
        self::review($entry, $reviewerid, entry::STATUS_REJECTED, $reason);
    }

    /**
     * Reverses an approved entry with a reason and notifies the member. The entry stays in the
     * member's logbook as reversed and no longer counts; this cannot be undone.
     *
     * @param entry $entry The entry.
     * @param int $reviewerid The staff member.
     * @param string $reason Why the approval was reversed; required.
     * @throws \moodle_exception If the reviewer may not reverse the entry or no reason is given.
     */
    public static function reverse(entry $entry, int $reviewerid, string $reason): void {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \moodle_exception('error:reversalreasonrequired', 'local_cpdlog');
        }
        self::review($entry, $reviewerid, entry::STATUS_REVERSED, $reason);
    }

    /**
     * Records an approval, rejection or reversal, one reviewer at a time.
     *
     * Two approvers can act on the same entry at once, each holding a copy loaded before the other
     * acted. A lock per entry serialises them, and the entry is reloaded under the lock, so only
     * the first action is recorded, logged and notified; the second is refused.
     *
     * @param entry $entry The entry; reloaded from the database.
     * @param int $reviewerid The staff member.
     * @param string $status entry::STATUS_APPROVED, STATUS_REJECTED or STATUS_REVERSED.
     * @param string|null $reason The reason, when rejecting or reversing.
     * @throws \moodle_exception If the entry is being reviewed by someone else or the action is not allowed.
     */
    private static function review(entry $entry, int $reviewerid, string $status, ?string $reason = null): void {
        global $DB;
        $factory = \core\lock\lock_config::get_lock_factory('local_cpdlog_review');
        $lock = $factory->get_lock('entry' . $entry->get('id'), self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new \moodle_exception('error:reviewbusy', 'local_cpdlog');
        }
        try {
            $entry->read();
            if ($status === entry::STATUS_REVERSED) {
                if (!self::can_reverse($entry, $reviewerid)) {
                    throw new \moodle_exception('error:entrynotreversible', 'local_cpdlog');
                }
            } else if (!self::can_review($entry, $reviewerid)) {
                throw new \moodle_exception('error:entrynotreviewable', 'local_cpdlog');
            }

            $transaction = $DB->start_delegated_transaction();
            $entry->set('status', $status);
            if ($status === entry::STATUS_REVERSED) {
                $entry->set('reversedby', $reviewerid);
                $entry->set('timereversed', time());
                $entry->set('reversalreason', $reason);
            } else {
                $entry->set('reviewedby', $reviewerid);
                $entry->set('timereviewed', time());
                if ($status === entry::STATUS_REJECTED) {
                    $entry->set('rejectionreason', $reason);
                }
            }
            $entry->update();
            $eventclass = match ($status) {
                entry::STATUS_APPROVED => entry_approved::class,
                entry::STATUS_REJECTED => entry_rejected::class,
                entry::STATUS_REVERSED => entry_reversed::class,
            };
            $eventclass::create_from_entry($entry)->trigger();
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }
        notifier::entry_reviewed($entry);
    }

    /**
     * Approves several entries, skipping any the reviewer may no longer review.
     *
     * @param int[] $entryids The entries.
     * @param int $reviewerid The staff member.
     * @return int How many entries were approved.
     * @throws \moodle_exception If more than BULK_LIMIT entries are given.
     */
    public static function approve_many(array $entryids, int $reviewerid): int {
        $entryids = array_unique(array_map('intval', $entryids));
        if (count($entryids) > self::BULK_LIMIT) {
            throw new \moodle_exception('error:bulklimit', 'local_cpdlog', '', self::BULK_LIMIT);
        }
        $approved = 0;
        foreach ($entryids as $entryid) {
            $entry = entry::get_record(['id' => $entryid]);
            if (!$entry || !self::can_review($entry, $reviewerid)) {
                continue;
            }
            try {
                self::approve($entry, $reviewerid);
                $approved++;
            } catch (\moodle_exception $e) {
                // Another approver got there first; any other failure is real.
                if (!in_array($e->errorcode, ['error:entrynotreviewable', 'error:reviewbusy'], true)) {
                    throw $e;
                }
            }
        }
        return $approved;
    }
}
