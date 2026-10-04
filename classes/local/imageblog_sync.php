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
 * added), and when the member's CPD data was deleted after it was awarded or a deletion is queued,
 * so the deletion tool's work is not undone. Nothing runs unless the image blog is installed and
 * local_cpdlog/imageblogenabled is on.
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
        foreach (category::get_records([], 'sortorder') as $category) {
            $choices[$category->get('shortname')] = format_string($category->get('name'));
        }
        return $choices;
    }

    /**
     * Returns the category new image blog entries are filed under.
     *
     * Falls back to Educational activities (EA), then to the first category, if the setting names a
     * category that no longer exists.
     *
     * @return category
     */
    public static function get_category(): category {
        $shortname = (string) get_config('local_cpdlog', 'imageblogcategory');
        foreach ([$shortname, 'EA'] as $candidate) {
            if ($candidate !== '' && ($category = category::get_record(['shortname' => $candidate]))) {
                return $category;
            }
        }
        $categories = category::get_records([], 'sortorder', 'ASC', 0, 1);
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
                   AND NOT EXISTS (
                       SELECT 1
                         FROM {" . data_deleter::TABLE . "} d
                        WHERE d.userid = a.userid
                          AND (d.status = :queued OR (d.status = :done AND d.timecompleted >= a.timeawarded))
                   )
              ORDER BY a.id";
        $params = [
            'source' => entry::SOURCE_IMAGEBLOG,
            'approved' => entry::STATUS_APPROVED,
            'queued' => data_deleter::STATUS_QUEUED,
            'done' => data_deleter::STATUS_DONE,
        ];
        $categoryid = (int) self::get_category()->get('id');
        $awards = $DB->get_recordset_sql($sql, $params);
        foreach ($awards as $award) {
            if ($award->entryid) {
                self::update_entry(new entry($award->entryid), $award);
                $result->updated++;
            } else if (self::create_entry($award, $categoryid)) {
                $result->created++;
            } else {
                $result->skipped++;
            }
        }
        $awards->close();
    }

    /**
     * Creates an approved entry for an award, if a reporting period contains its date.
     *
     * @param \stdClass $award The award, with the post title.
     * @param int $categoryid The category to file it under.
     * @return bool Whether an entry was created.
     */
    private static function create_entry(\stdClass $award, int $categoryid): bool {
        $period = entry_manager::find_period((int) $award->timeawarded);
        if (!$period) {
            return false;
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
        return true;
    }

    /**
     * Brings an entry back in line with its award: same hours, and approved again if it was reversed.
     *
     * @param entry $entry The entry.
     * @param \stdClass $award The award.
     */
    private static function update_entry(entry $entry, \stdClass $award): void {
        $entry->set('hours', round((float) $award->hours, 2));
        if ($entry->get('status') !== entry::STATUS_APPROVED) {
            $entry->set('status', entry::STATUS_APPROVED);
            $entry->set('timereviewed', (int) $award->timeawarded);
            $entry->set('reversedby', null);
            $entry->set('timereversed', null);
            $entry->set('reversalreason', null);
        }
        $entry->update();
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
