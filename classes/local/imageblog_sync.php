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
 * Copies CPD hours awarded by the image blog (local_imageblog) into the logbook.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local;

use local_cpdlog\persistent\category;
use local_cpdlog\persistent\entry;
use local_cpdlog\persistent\period;

/**
 * Copies CPD hours awarded by the image blog (local_imageblog) into the logbook.
 *
 * The image blog keeps its awards in local_imageblog_case_cpd, one row per case, member and reason
 * (diagnosis, best answer or reading the outcome). Each award becomes an approved logbook entry with
 * source "imageblog" and the reference "postid:userid:reason", so running again updates rather than
 * duplicates it. The image blog stays the owner: when an award's hours change the entry follows,
 * when an award disappears the entry is reversed, and if it comes back the entry is approved again.
 * Entries have no course; their course name reads "Image blog".
 *
 * An award is skipped when no reporting period contains its date (it is picked up once a period is
 * added), while a deletion of the member's CPD data is queued, and when it already existed when a
 * deletion ran, so the deletion tool's work is not undone. Nothing runs unless the image blog is
 * installed and local_cpdlog/imageblogenabled is on.
 */
final class imageblog_sync
{
    /** @var string The image blog's table of awards. */
    const AWARD_TABLE = 'local_imageblog_case_cpd';

    /** @var string The image blog's table of posts. */
    const POST_TABLE = 'local_imageblog_posts';

    /** @var string[] Award reasons the image blog records, each with a string reason:<reason>. */
    const REASONS = ['bestanswer', 'participation', 'view'];

    /**
     * Whether the image blog is installed.
     *
     * @return bool
     */
    public static function is_installed(): bool {
        return \core_component::get_component_directory('local_imageblog') !== null
            && get_config('local_imageblog', 'version') !== false;
    }

    /**
     * Whether awards are copied: the image blog is installed and the setting is on.
     *
     * The setting only appears once the image blog is installed, so until an administrator saves it
     * it is unset; that counts as its default, on.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        $enabled = get_config('local_cpdlog', 'imageblogenabled');
        return self::is_installed() && ($enabled === false || (bool) $enabled);
    }

    /**
     * Returns the categories that image blog entries can be filed under, for the setting.
     *
     * @return string[] Category names keyed by short name.
     */
    public static function get_category_choices(): array {
        $choices = [];
        foreach (category::get_records(['enabled' => 1], 'sortorder') as $category) {
            $choices[$category->get('shortname')] = format_string($category->get('name'));
        }
        return $choices;
    }

    /**
     * Returns the category new image blog entries are filed under.
     *
     * Only enabled categories are used, as for entries members log. Falls back to Educational
     * activities (EA), then to the first enabled category, if the setting names a category that is
     * disabled or no longer exists.
     *
     * @return category
     */
    public static function get_category(): category {
        $shortname = (string) get_config('local_cpdlog', 'imageblogcategory');
        foreach ([$shortname, 'EA'] as $candidate) {
            if ($candidate !== '' && ($category = category::get_record(['shortname' => $candidate, 'enabled' => 1]))) {
                return $category;
            }
        }
        $categories = category::get_records(['enabled' => 1], 'sortorder', 'ASC', 0, 1);
        if (!$categories) {
            throw new \moodle_exception('invalidrecord', 'error', '', category::TABLE);
        }
        return reset($categories);
    }

    /**
     * Copies new and changed awards into the logbook and reverses entries whose award has gone.
     *
     * @return \stdClass Counts: created, updated, reversed and skipped (no period).
     */
    public static function sync(): \stdClass {
        $result = (object) ['created' => 0, 'updated' => 0, 'reversed' => 0, 'skipped' => 0];
        if (!self::is_enabled()) {
            return $result;
        }
        self::copy_awards($result);
        self::reverse_withdrawn($result);
        return $result;
    }

    /**
     * Returns SQL for an award's reference, matching entry::externalref.
     *
     * @param string $alias The award table alias.
     * @return string
     */
    private static function ref_sql(string $alias): string {
        global $DB;
        return $DB->sql_concat("{$alias}.postid", "':'", "{$alias}.userid", "':'", "{$alias}.reason");
    }

    /**
     * Creates entries for new awards, and updates entries whose award changed or came back.
     *
     * Awards are handled member by member under the same lock that queuing a deletion takes, and each
     * member's deletion register is read inside that lock. A deletion queued during a run therefore
     * either waits for the member's writes (and then removes them) or is seen before any is made.
     *
     * @param \stdClass $result The counts to add to.
     */
    private static function copy_awards(\stdClass $result): void {
        global $DB;
        $ref = self::ref_sql('a');
        $sql = "SELECT a.id, a.postid, a.userid, a.reason, a.hours, a.timeawarded, p.title,
                       e.id AS entryid
                  FROM {" . self::AWARD_TABLE . "} a
                  JOIN {" . self::POST_TABLE . "} p ON p.id = a.postid
                  JOIN {user} u ON u.id = a.userid
             LEFT JOIN {" . entry::TABLE . "} e ON e.source = :source AND e.externalref = {$ref}
                 WHERE a.hours > 0
                   AND (e.id IS NULL OR e.hours <> a.hours OR e.status <> :approved)
              ORDER BY a.userid, a.id";
        $params = ['source' => entry::SOURCE_IMAGEBLOG, 'approved' => entry::STATUS_APPROVED];
        $categoryid = (int) self::get_category()->get('id');
        $lockfactory = \core\lock\lock_config::get_lock_factory(data_deleter::LOCK_TYPE);
        $userid = null;
        $lock = false;
        $cutoff = null;
        $awards = $DB->get_recordset_sql($sql, $params);
        try {
            foreach ($awards as $award) {
                if ((int) $award->userid !== $userid) {
                    if ($lock) {
                        $lock->release();
                    }
                    $userid = (int) $award->userid;
                    $lock = $lockfactory->get_lock(data_deleter::lock_key($userid), 5);
                    $cutoff = $lock ? self::deletion_cutoff($userid) : null;
                }
                // Without the lock, or with a deletion queued, the member's awards wait for the next run.
                if (!$lock || $cutoff === false || ($cutoff !== null && self::is_before($award, $cutoff))) {
                    continue;
                }
                $outcome = $award->entryid
                    ? self::update_entry(new entry($award->entryid), $award)
                    : self::create_entry($award, $categoryid);
                $result->$outcome++;
            }
        } finally {
            $awards->close();
            if ($lock) {
                $lock->release();
            }
        }
    }

    /**
     * Returns how far a member's completed CPD data deletions reach into the image blog's awards.
     *
     * @param int $userid The member.
     * @return \stdClass|false|null False while a deletion is queued; null if none has run; otherwise
     *                              awardid (the highest award id when the latest deletion ran, or null
     *                              for deletions made before that was recorded) and timecompleted.
     */
    private static function deletion_cutoff(int $userid) {
        global $DB;
        if ($DB->record_exists(data_deleter::TABLE, ['userid' => $userid, 'status' => data_deleter::STATUS_QUEUED])) {
            return false;
        }
        $sql = 'SELECT MAX(imageblogawardid) AS awardid, MAX(timecompleted) AS timecompleted
                  FROM {' . data_deleter::TABLE . '}
                 WHERE userid = :userid AND status = :done';
        $cutoff = $DB->get_record_sql($sql, ['userid' => $userid, 'done' => data_deleter::STATUS_DONE]);
        return $cutoff->timecompleted === null ? null : $cutoff;
    }

    /**
     * Whether an award existed before the member's CPD data was deleted, so must not be copied back.
     *
     * The image blog re-stamps an award's time whenever it refreshes it (for example each time the
     * member reads the outcome again), so the award id recorded by the deletion decides. Deletions
     * recorded before that id was kept fall back to comparing times.
     *
     * @param \stdClass $award The award.
     * @param \stdClass $cutoff From deletion_cutoff().
     * @return bool
     */
    private static function is_before(\stdClass $award, \stdClass $cutoff): bool {
        if ($cutoff->awardid !== null && (int) $award->id <= (int) $cutoff->awardid) {
            return true;
        }
        return (int) $award->timeawarded <= (int) $cutoff->timecompleted;
    }

    /**
     * Creates an approved entry for an award, if a reporting period contains its date.
     *
     * @param \stdClass $award The award, with the post title.
     * @param int $categoryid The category to file it under.
     * @return string The count to add to: created, or skipped when no period contains its date.
     */
    private static function create_entry(\stdClass $award, int $categoryid): string {
        $period = entry_manager::find_period((int) $award->timeawarded);
        if (!$period) {
            return 'skipped';
        }
        $entry = new entry(0, (object) [
            'userid' => (int) $award->userid,
            'categoryid' => $categoryid,
            'periodid' => $period->get('id'),
            'courseid' => null,
            'coursename' => get_string('imageblog', 'local_cpdlog'),
            'hours' => round((float) $award->hours, 2),
            'activitydate' => (int) $award->timeawarded,
            'description' => self::describe($award),
            'descriptionformat' => FORMAT_PLAIN,
            'status' => entry::STATUS_APPROVED,
            'source' => entry::SOURCE_IMAGEBLOG,
            'externalref' => $award->postid . ':' . $award->userid . ':' . $award->reason,
            'timesubmitted' => (int) $award->timeawarded,
            'timereviewed' => (int) $award->timeawarded,
        ]);
        $entry->create();
        return 'created';
    }

    /**
     * Brings an entry back in line with its award: same hours, and approved again if it was reversed.
     *
     * An approved entry keeps its date when only the hours change, because the image blog re-stamps an
     * award whenever it refreshes it. An award that comes back after being withdrawn is new, so the
     * entry takes its date and period, and waits like a new award if no period contains that date.
     *
     * @param entry $entry The entry.
     * @param \stdClass $award The award.
     * @return string The count to add to: updated, or skipped when no period contains a returning award.
     */
    private static function update_entry(entry $entry, \stdClass $award): string {
        if ($entry->get('status') !== entry::STATUS_APPROVED) {
            $period = entry_manager::find_period((int) $award->timeawarded);
            if (!$period) {
                return 'skipped';
            }
            $entry->set('periodid', $period->get('id'));
            $entry->set('activitydate', (int) $award->timeawarded);
            $entry->set('status', entry::STATUS_APPROVED);
            $entry->set('timereviewed', (int) $award->timeawarded);
            $entry->set('reversedby', null);
            $entry->set('timereversed', null);
            $entry->set('reversalreason', null);
        }
        $entry->set('hours', round((float) $award->hours, 2));
        $entry->update();
        return 'updated';
    }

    /**
     * Reverses approved image blog entries whose award no longer exists in the image blog.
     *
     * @param \stdClass $result The counts to add to.
     */
    private static function reverse_withdrawn(\stdClass $result): void {
        global $DB;
        $ref = self::ref_sql('a');
        $select = "source = :source AND status = :approved
                   AND NOT EXISTS (
                       SELECT 1 FROM {" . self::AWARD_TABLE . "} a
                        WHERE a.hours > 0 AND {$ref} = {" . entry::TABLE . "}.externalref
                   )";
        $params = ['source' => entry::SOURCE_IMAGEBLOG, 'approved' => entry::STATUS_APPROVED];
        foreach (entry::get_records_select($select, $params) as $entry) {
            $entry->set('status', entry::STATUS_REVERSED);
            $entry->set('timereversed', time());
            $entry->set('reversalreason', get_string('imageblog:withdrawn', 'local_cpdlog'));
            $entry->update();
            $result->reversed++;
        }
    }

    /**
     * Describes an award for the entry: the case title and why the hours were given.
     *
     * @param \stdClass $award The award, with the post title.
     * @return string Plain text.
     */
    private static function describe(\stdClass $award): string {
        $reason = in_array($award->reason, self::REASONS, true)
            ? get_string('imageblog:reason:' . $award->reason, 'local_cpdlog')
            : $award->reason;
        return get_string('imageblog:description', 'local_cpdlog', (object) [
            'title' => format_string($award->title, true, ['context' => \context_system::instance()]),
            'reason' => $reason,
        ]);
    }
}
