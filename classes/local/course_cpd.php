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
 * Releases course completions to members' CPD logbooks, as staff choose.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local;

use local_cpdlog\event\entry_created;
use local_cpdlog\event\entry_approved;
use local_cpdlog\persistent\category;
use local_cpdlog\persistent\entry;

/**
 * Releases course completions to members' CPD logbooks, as staff choose.
 *
 * A course awards CPD when its "CPD hours" custom field is above zero; its "CPD category" field
 * says which CPD category, falling back to the setting local_cpdlog/completioncategory. Hours above
 * the site's maximum per entry are capped at it.
 *
 * Nothing is added automatically (decision 21). Members who complete such a course wait on the
 * course's release checklist until an approver releases them, which adds an approved entry for
 * those hours at the completion date with the approver as its reviewer, or excludes them, which
 * keeps them out until someone releases them later. local_cpdlog_completion records each decision,
 * so a completion is released at most once. Completions are held back while a deletion of the
 * member's CPD data is queued, or if they happened before one ran, and wait while no open reporting
 * period covers their date. Approvers get a notice when new completions are waiting. The setting
 * local_cpdlog/completionenabled switches it all off.
 *
 * The two custom fields belong to the "CPD logbook" course custom field category. The options of
 * the category field list every CPD category in creation order, as "Name (SHORTNAME)", and only ever
 * grow, because Moodle stores the chosen option's position: categories are disabled, never deleted,
 * so each position keeps meaning the same category.
 */
final class course_cpd
{
    /** @var string The log of completions that created an entry. */
    const TABLE = 'local_cpdlog_completion';

    /** @var string Short name of the course custom field holding the CPD hours. */
    const FIELD_HOURS = 'cpdlog_hours';

    /** @var string Short name of the course custom field holding the CPD category. */
    const FIELD_CATEGORY = 'cpdlog_category';

    /** @var string A completion released to the member's logbook as an approved entry. */
    const STATUS_RELEASED = 'released';

    /** @var string A completion staff chose not to release; it can still be released later. */
    const STATUS_EXCLUDED = 'excluded';

    /**
     * Whether completions create entries.
     *
     * The setting defaults to on; until an administrator saves it, it is unset, which counts as on.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        $enabled = get_config('local_cpdlog', 'completionenabled');
        return $enabled === false || (bool) $enabled;
    }

    /**
     * Returns the course custom field handler.
     *
     * @return \core_course\customfield\course_handler
     */
    private static function handler(): \core_course\customfield\course_handler {
        return \core_course\customfield\course_handler::create();
    }

    /**
     * Returns one of the plugin's course custom fields, if it exists.
     *
     * @param string $shortname FIELD_HOURS or FIELD_CATEGORY.
     * @return \core_customfield\field_controller|null
     */
    public static function get_field(string $shortname): ?\core_customfield\field_controller {
        foreach (self::handler()->get_categories_with_fields() as $category) {
            foreach ($category->get_fields() as $field) {
                if ($field->get('shortname') === $shortname) {
                    return $field;
                }
            }
        }
        return null;
    }

    /**
     * Creates the "CPD logbook" course custom fields if they are missing, and refreshes the category options.
     *
     * Safe to run more than once.
     */
    public static function setup_fields(): void {
        $handler = self::handler();
        if (!self::get_field(self::FIELD_HOURS) || !self::get_field(self::FIELD_CATEGORY)) {
            $categoryid = null;
            foreach ($handler->get_categories_with_fields() as $category) {
                if ($category->get('name') === get_string('pluginname', 'local_cpdlog')) {
                    $categoryid = (int) $category->get('id');
                }
            }
            $categoryid = $categoryid ?? $handler->create_category(get_string('pluginname', 'local_cpdlog'));
            $category = \core_customfield\category_controller::create($categoryid);
            // Locked, so only users who may change locked fields (managers) set them; shown on course listings.
            $common = ['required' => 0, 'uniquevalues' => 0, 'locked' => 1, 'visibility' => 2];
            if (!self::get_field(self::FIELD_HOURS)) {
                self::create_field($category, 'number', self::FIELD_HOURS, 'coursefield:hours', $common + [
                    'defaultvalue' => '',
                    'minimumvalue' => '0',
                    'maximumvalue' => '',
                    'decimalplaces' => 2,
                    'display' => '{value}',
                    'displaywhenzero' => 0,
                ]);
            }
            if (!self::get_field(self::FIELD_CATEGORY)) {
                self::create_field($category, 'select', self::FIELD_CATEGORY, 'coursefield:category', $common + [
                    'options' => self::category_options(''),
                    'defaultvalue' => '',
                ]);
            }
        }
        self::sync_category_options();
    }

    /**
     * Removes the two course custom fields, with every course's values, and their category if it is
     * then empty. Used when the plugin is uninstalled.
     */
    public static function remove_fields(): void {
        $handler = self::handler();
        foreach ([self::FIELD_HOURS, self::FIELD_CATEGORY] as $shortname) {
            if ($field = self::get_field($shortname)) {
                $handler->delete_field_configuration($field);
            }
        }
        foreach ($handler->get_categories_with_fields() as $category) {
            if ($category->get('name') === get_string('pluginname', 'local_cpdlog') && !$category->get_fields()) {
                $handler->delete_category($category);
            }
        }
    }

    /**
     * Creates one course custom field.
     *
     * @param \core_customfield\category_controller $category The custom field category.
     * @param string $type The field type.
     * @param string $shortname The field short name.
     * @param string $string The string identifier of its name; the description is '<string>_desc'.
     * @param array $configdata The field configuration.
     */
    private static function create_field(
        \core_customfield\category_controller $category,
        string $type,
        string $shortname,
        string $string,
        array $configdata
    ): void {
        $field = \core_customfield\field_controller::create(0, (object) ['type' => $type], $category);
        $category->get_handler()->save_field_configuration($field, (object) [
            'name' => get_string($string, 'local_cpdlog'),
            'shortname' => $shortname,
            'description' => get_string($string . '_desc', 'local_cpdlog'),
            'descriptionformat' => FORMAT_HTML,
            'configdata' => $configdata,
        ]);
    }

    /**
     * Returns the category field's options: every CPD category in creation order, one per line.
     *
     * Existing options keep their position. Categories are only ever disabled, so the list only grows.
     *
     * @param string $current The options the field has now.
     * @return string
     */
    private static function category_options(string $current): string {
        $lines = [];
        foreach (category::get_records([], 'id') as $category) {
            $lines[] = str_replace(["\r", "\n"], ' ', $category->get('name')) . ' (' . $category->get('shortname') . ')';
        }
        // Keep any extra lines someone added by hand, so no stored position changes meaning.
        $existing = preg_split("/\s*\n\s*/", trim($current), -1, PREG_SPLIT_NO_EMPTY);
        return implode("\n", $lines + $existing);
    }

    /**
     * Refreshes the category field's options after CPD categories are added or renamed.
     */
    public static function sync_category_options(): void {
        $field = self::get_field(self::FIELD_CATEGORY);
        if (!$field) {
            return;
        }
        $current = (string) $field->get_configdata_property('options');
        $options = self::category_options($current);
        if ($options !== $current) {
            $configdata = $field->get('configdata');
            $configdata['options'] = $options;
            $field->set('configdata', json_encode($configdata));
            $field->save();
        }
    }

    /**
     * Returns the CPD a course awards, or null if it awards none.
     *
     * @param int $courseid The course.
     * @return \stdClass|null hours (float) and category (an enabled category).
     */
    public static function get_course_cpd(int $courseid): ?\stdClass {
        $hours = 0.0;
        $shortname = '';
        foreach (self::handler()->get_instance_data($courseid, true) as $data) {
            $field = $data->get_field()->get('shortname');
            if ($field === self::FIELD_HOURS) {
                $hours = round((float) $data->get_value(), 2);
            } else if ($field === self::FIELD_CATEGORY) {
                $label = (string) $data->export_value();
                if (preg_match('/\(([^()]+)\)$/', $label, $match)) {
                    $shortname = $match[1];
                }
            }
        }
        if ($hours < 0.01) {
            return null;
        }
        // No more than an entry may claim, as for entries members log.
        $hours = min($hours, entry_manager::get_max_hours());
        return (object) ['hours' => $hours, 'category' => self::resolve_category($shortname)];
    }

    /**
     * Returns the enabled category with this short name, or the default category for course CPD.
     *
     * @param string $shortname The chosen category's short name, or ''.
     * @return category
     */
    public static function resolve_category(string $shortname): category {
        $default = (string) get_config('local_cpdlog', 'completioncategory');
        foreach ([$shortname, $default, 'EA'] as $candidate) {
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
     * Returns the categories offered for the default course CPD category setting.
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
     * Returns the completions of a course that wait to be released or excluded.
     *
     * Members whose CPD data deletion is queued, or ran after they completed, are left out.
     *
     * @param int $courseid The course.
     * @return \stdClass[] One per member: userid, timecompleted, the user name fields, and releasable
     *                     (whether an open reporting period covers the completion date).
     */
    public static function get_waiting(int $courseid): array {
        global $DB;
        $names = \core_user\fields::for_name()->get_sql('u', true);
        $sql = "SELECT cc.userid, cc.timecompleted, u.email {$names->selects}
                  FROM {course_completions} cc
                  JOIN {user} u ON u.id = cc.userid AND u.deleted = 0
                 WHERE cc.course = :courseid AND cc.timecompleted > 0
                   AND NOT EXISTS (SELECT 1 FROM {" . self::TABLE . "} l WHERE l.userid = cc.userid AND l.courseid = cc.course)
                   AND NOT EXISTS (SELECT 1 FROM {" . data_deleter::TABLE . "} x
                                    WHERE x.userid = cc.userid
                                      AND (x.status = :queued OR (x.status = :done AND x.timecompleted >= cc.timecompleted)))
              ORDER BY u.lastname, u.firstname, u.id";
        $params = $names->params + [
            'courseid' => $courseid,
            'queued' => data_deleter::STATUS_QUEUED,
            'done' => data_deleter::STATUS_DONE,
        ];
        $waiting = $DB->get_records_sql($sql, $params);
        foreach ($waiting as $row) {
            $period = entry_manager::find_period((int) $row->timecompleted);
            $row->releasable = $period && !$period->is_closed();
        }
        return $waiting;
    }

    /**
     * Returns the members excluded from a course's CPD, with who excluded them.
     *
     * @param int $courseid The course.
     * @return \stdClass[] One per member: userid, timecompleted, timemodified, the member's name fields,
     *                     and the staff member's name fields prefixed "staff".
     */
    public static function get_excluded(int $courseid): array {
        global $DB;
        $names = \core_user\fields::for_name();
        $member = $names->get_sql('u', true);
        $staff = $names->get_sql('s', true, 'staff', 'staffid', false);
        $sql = "SELECT l.userid, l.timecompleted, l.timemodified, u.email {$member->selects}, {$staff->selects}
                  FROM {" . self::TABLE . "} l
                  JOIN {user} u ON u.id = l.userid
             LEFT JOIN {user} s ON s.id = l.actionedby
                 WHERE l.courseid = :courseid AND l.status = :excluded
              ORDER BY u.lastname, u.firstname, u.id";
        return $DB->get_records_sql($sql, $member->params + $staff->params + [
            'courseid' => $courseid,
            'excluded' => self::STATUS_EXCLUDED,
        ]);
    }

    /**
     * Counts each course's completions waiting to be released, for the courses that award CPD.
     *
     * @return int[] Counts keyed by course id; courses with none waiting are left out.
     */
    public static function count_waiting(): array {
        global $DB;
        $field = self::get_field(self::FIELD_HOURS);
        if (!$field) {
            return [];
        }
        $sql = 'SELECT cc.course, COUNT(1) AS waiting
                  FROM {course_completions} cc
                  JOIN {customfield_data} d ON d.instanceid = cc.course AND d.fieldid = :fieldid AND d.decvalue > 0
                  JOIN {user} u ON u.id = cc.userid AND u.deleted = 0
                 WHERE cc.timecompleted > 0
                   AND NOT EXISTS (SELECT 1 FROM {' . self::TABLE . '} l WHERE l.userid = cc.userid AND l.courseid = cc.course)
                   AND NOT EXISTS (SELECT 1 FROM {' . data_deleter::TABLE . '} x
                                    WHERE x.userid = cc.userid
                                      AND (x.status = :queued OR (x.status = :done AND x.timecompleted >= cc.timecompleted)))
              GROUP BY cc.course';
        $params = ['fieldid' => $field->get('id'), 'queued' => data_deleter::STATUS_QUEUED, 'done' => data_deleter::STATUS_DONE];
        return array_map('intval', $DB->get_records_sql_menu($sql, $params));
    }

    /**
     * Releases a member's completion of a course to their logbook as an approved entry.
     *
     * The staff member releasing it is recorded as its reviewer, and the member is told. Runs under
     * the per-member lock that queuing a deletion of their CPD data takes. A member excluded earlier
     * can be released.
     *
     * @param int $userid The member.
     * @param int $courseid The course.
     * @param int $staffid The staff member releasing it.
     * @return string What happened: released, exists (already released), or skipped (no completion,
     *                no CPD for the course, switched off, blocked by a deletion, or no open period).
     */
    public static function release(int $userid, int $courseid, int $staffid): string {
        global $DB;
        if (!self::is_enabled()) {
            return 'skipped';
        }
        $cpd = self::get_course_cpd($courseid);
        $timecompleted = (int) $DB->get_field('course_completions', 'timecompleted', ['userid' => $userid, 'course' => $courseid]);
        if (!$cpd || !$timecompleted) {
            return 'skipped';
        }
        $lock = \core\lock\lock_config::get_lock_factory(data_deleter::LOCK_TYPE)
            ->get_lock(data_deleter::lock_key($userid), 5);
        if (!$lock) {
            return 'skipped';
        }
        try {
            $log = $DB->get_record(self::TABLE, ['userid' => $userid, 'courseid' => $courseid]);
            $ref = 'completion:' . $courseid . ':' . $userid;
            $entryparams = ['source' => entry::SOURCE_MOODLE, 'ref' => $ref];
            if (
                ($log && $log->status === self::STATUS_RELEASED)
                || entry::record_exists_select('source = :source AND externalref = :ref', $entryparams)
            ) {
                return 'exists';
            }
            if (self::blocked_by_deletion($userid, $timecompleted)) {
                return 'skipped';
            }
            // Entries in a closed period cannot be changed, so the completion waits until it reopens.
            $period = entry_manager::find_period($timecompleted);
            if (!$period || $period->is_closed()) {
                return 'skipped';
            }
            $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname', MUST_EXIST);

            $now = time();
            $transaction = $DB->start_delegated_transaction();
            $entry = (new entry(0, (object) [
                'userid' => $userid,
                'categoryid' => $cpd->category->get('id'),
                'periodid' => $period->get('id'),
                'courseid' => $courseid,
                'coursename' => $course->fullname,
                'hours' => $cpd->hours,
                'activitydate' => $timecompleted,
                'description' => get_string('coursecompleted:description', 'local_cpdlog', $course->fullname),
                'descriptionformat' => FORMAT_PLAIN,
                'status' => entry::STATUS_APPROVED,
                'source' => entry::SOURCE_MOODLE,
                'externalref' => $ref,
                'timesubmitted' => $now,
                'reviewedby' => $staffid,
                'timereviewed' => $now,
            ]))->create();
            $record = (object) [
                'userid' => $userid,
                'courseid' => $courseid,
                'entryid' => $entry->get('id'),
                'status' => self::STATUS_RELEASED,
                'timecompleted' => $timecompleted,
                'actionedby' => $staffid,
                'timemodified' => $now,
            ];
            if ($log) {
                $record->id = $log->id;
                $DB->update_record(self::TABLE, $record);
            } else {
                $record->timecreated = $now;
                $DB->insert_record(self::TABLE, $record);
            }
            entry_created::create_from_entry($entry)->trigger();
            entry_approved::create_from_entry($entry)->trigger();
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }
        notifier::entry_reviewed($entry);
        return 'released';
    }

    /**
     * Excludes a member's completion of a course, so it is not released; it can be released later.
     *
     * @param int $userid The member.
     * @param int $courseid The course.
     * @param int $staffid The staff member excluding it.
     * @return bool Whether it was excluded; false if already released or excluded, or not completed.
     */
    public static function exclude(int $userid, int $courseid, int $staffid): bool {
        global $DB;
        $timecompleted = (int) $DB->get_field('course_completions', 'timecompleted', ['userid' => $userid, 'course' => $courseid]);
        if (!$timecompleted || $DB->record_exists(self::TABLE, ['userid' => $userid, 'courseid' => $courseid])) {
            return false;
        }
        $now = time();
        $DB->insert_record(self::TABLE, (object) [
            'userid' => $userid,
            'courseid' => $courseid,
            'entryid' => null,
            'status' => self::STATUS_EXCLUDED,
            'timecompleted' => $timecompleted,
            'actionedby' => $staffid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        return true;
    }

    /**
     * Whether a deletion of the member's CPD data is queued, or ran after this completion.
     *
     * @param int $userid The member.
     * @param int $timecompleted When the course was completed.
     * @return bool
     */
    private static function blocked_by_deletion(int $userid, int $timecompleted): bool {
        global $DB;
        $select = 'userid = :userid AND (status = :queued OR (status = :done AND timecompleted >= :completed))';
        return $DB->record_exists_select(data_deleter::TABLE, $select, [
            'userid' => $userid,
            'queued' => data_deleter::STATUS_QUEUED,
            'done' => data_deleter::STATUS_DONE,
            'completed' => $timecompleted,
        ]);
    }

    /**
     * Tells approvers which courses have completions waiting to be released, when new ones arrived.
     *
     * Sends nothing unless a completion waiting now was completed since the last notice, so staff
     * are not reminded every day about the same people.
     *
     * @param int|null $now The time to work from; now if null.
     * @return int How many approvers were told.
     */
    public static function notify_waiting(?int $now = null): int {
        global $DB;
        $now = $now ?? time();
        if (!self::is_enabled()) {
            return 0;
        }
        $since = (int) get_config('local_cpdlog', 'completionnoticetime');
        $waiting = self::count_waiting();
        if (!$waiting) {
            set_config('completionnoticetime', $now, 'local_cpdlog');
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($waiting), SQL_PARAMS_NAMED);
        $select = "course {$insql} AND timecompleted > :since
                   AND NOT EXISTS (SELECT 1 FROM {" . self::TABLE . "} l
                                    WHERE l.userid = {course_completions}.userid AND l.courseid = {course_completions}.course)";
        if (!$DB->record_exists_select('course_completions', $select, $params + ['since' => $since])) {
            return 0;
        }
        $courses = $DB->get_records_list('course', 'id', array_keys($waiting), 'fullname', 'id, fullname');
        $told = notifier::completions_waiting($courses, $waiting);
        set_config('completionnoticetime', $now, 'local_cpdlog');
        return $told;
    }
}
