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
 * Rules for staff approving and rejecting submitted CPD entries.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\local;

use local_cpdlog\event\entry_approved;
use local_cpdlog\event\entry_rejected;
use local_cpdlog\persistent\entry;
use local_cpdlog\persistent\period;

/**
 * Rules for staff approving and rejecting submitted CPD entries.
 *
 * Callers must still check login, local/cpdlog:approve and the session key; this class checks the
 * entry's state and that reviewers never review their own entries.
 */
final class review_manager
{
    /** @var int Most entries approved in one bulk action, and the approval queue's page size. */
    const BULK_LIMIT = 50;

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
     * Approves a submitted entry and notifies the member.
     *
     * @param entry $entry The entry.
     * @param int $reviewerid The staff member.
     * @throws \moodle_exception If the reviewer may not review the entry.
     */
    public static function approve(entry $entry, int $reviewerid): void {
        global $DB;
        if (!self::can_review($entry, $reviewerid)) {
            throw new \moodle_exception('error:entrynotreviewable', 'local_cpdlog');
        }
        $transaction = $DB->start_delegated_transaction();
        $entry->set('status', entry::STATUS_APPROVED);
        $entry->set('reviewedby', $reviewerid);
        $entry->set('timereviewed', time());
        $entry->update();
        entry_approved::create_from_entry($entry)->trigger();
        $transaction->allow_commit();
        notifier::entry_reviewed($entry);
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
        global $DB;
        if (!self::can_review($entry, $reviewerid)) {
            throw new \moodle_exception('error:entrynotreviewable', 'local_cpdlog');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new \moodle_exception('error:reasonrequired', 'local_cpdlog');
        }
        $transaction = $DB->start_delegated_transaction();
        $entry->set('status', entry::STATUS_REJECTED);
        $entry->set('reviewedby', $reviewerid);
        $entry->set('timereviewed', time());
        $entry->set('rejectionreason', $reason);
        $entry->update();
        entry_rejected::create_from_entry($entry)->trigger();
        $transaction->allow_commit();
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
            if ($entry && self::can_review($entry, $reviewerid)) {
                self::approve($entry, $reviewerid);
                $approved++;
            }
        }
        return $approved;
    }
}
