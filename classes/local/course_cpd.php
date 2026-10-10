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
 * Creates CPD entries automatically when members complete courses that award CPD.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local;

use local_cpdlog\event\entry_created;
use local_cpdlog\event\entry_submitted;
use local_cpdlog\persistent\category;
use local_cpdlog\persistent\entry;

/**
 * Creates CPD entries automatically when members complete courses that award CPD.
 *
 * A course awards CPD when its "CPD hours" custom field is above zero; its "CPD category" field
 * says which CPD category, falling back to the setting local_cpdlog/completioncategory. Hours above
 * the site's maximum per entry are capped at it. When a member
 * completes such a course, an entry for those hours is created at the completion date and submitted
 * for approval, so an approver checks it like any other entry (decision 21). The completion event
 * creates it at once; an hourly task also catches past completions and any the event missed.
 *
 * Each completion creates at most one entry, recorded in local_cpdlog_completion, so an entry the
 * member later deletes is not created again. Completions are skipped while a deletion of the
 * member's CPD data is queued, or if they happened before one ran, and wait while no open reporting
 * period covers their date. The setting local_cpdlog/completionenabled switches it off.
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

    /** @var int Most completions handled in one run of the task; the rest go on the next run. */
    const BATCH = 500;

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
     * Creates and submits the entry for one completion, if it awards CPD and has not done so already.
     *
     * Runs under the per-member lock that queuing a deletion of their CPD data takes.
     *
     * @param int $userid The member.
     * @param int $courseid The course.
     * @param int $timecompleted When they completed it.
     * @param bool $notify Whether to tell approvers; false when catching up on past completions.
     * @return string What happened: created, exists, skipped (waits for a later run) or none (awards no CPD).
     */
    public static function award(int $userid, int $courseid, int $timecompleted, bool $notify): string {
        global $DB;
        if (!self::is_enabled()) {
            return 'none';
        }
        $cpd = self::get_course_cpd($courseid);
        if (!$cpd) {
            return 'none';
        }
        $lock = \core\lock\lock_config::get_lock_factory(data_deleter::LOCK_TYPE)
            ->get_lock(data_deleter::lock_key($userid), 5);
        if (!$lock) {
            return 'skipped';
        }
        try {
            // The log, or the entry itself if a privacy request has cleared the log.
            $ref = 'completion:' . $courseid . ':' . $userid;
            $entryparams = ['source' => entry::SOURCE_MOODLE, 'ref' => $ref];
            if (
                $DB->record_exists(self::TABLE, ['userid' => $userid, 'courseid' => $courseid])
                || entry::record_exists_select('source = :source AND externalref = :ref', $entryparams)
            ) {
                return 'exists';
            }
            if (self::blocked_by_deletion($userid, $timecompleted)) {
                return 'skipped';
            }
            // A closed period's entries cannot be reviewed, so the completion waits until it reopens.
            $period = entry_manager::find_period($timecompleted);
            if (!$period || $period->is_closed()) {
                return 'skipped';
            }
            $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname', MUST_EXIST);

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
                'status' => entry::STATUS_SUBMITTED,
                'source' => entry::SOURCE_MOODLE,
                'externalref' => $ref,
                'timesubmitted' => time(),
            ]))->create();
            $DB->insert_record(self::TABLE, (object) [
                'userid' => $userid,
                'courseid' => $courseid,
                'entryid' => $entry->get('id'),
                'timecompleted' => $timecompleted,
                'timecreated' => time(),
            ]);
            entry_created::create_from_entry($entry)->trigger();
            entry_submitted::create_from_entry($entry)->trigger();
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }
        if ($notify) {
            notifier::entry_submitted($entry);
        }
        return 'created';
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
     * Creates entries for completions that have none yet, including those made before this was switched on.
     *
     * Approvers are not notified of these, so catching up on past completions does not flood them.
     *
     * @param int $limit Most completions to handle in this run.
     * @return \stdClass Counts: created and skipped.
     */
    public static function catch_up(int $limit = self::BATCH): \stdClass {
        global $DB;
        $result = (object) ['created' => 0, 'skipped' => 0];
        $field = self::get_field(self::FIELD_HOURS);
        if (!$field || !self::is_enabled()) {
            return $result;
        }
        $sql = 'SELECT cc.id, cc.userid, cc.course, cc.timecompleted
                  FROM {course_completions} cc
                  JOIN {customfield_data} d ON d.instanceid = cc.course AND d.fieldid = :fieldid AND d.decvalue > 0
                  JOIN {user} u ON u.id = cc.userid AND u.deleted = 0
                 WHERE cc.timecompleted > 0
                   AND NOT EXISTS (SELECT 1 FROM {' . self::TABLE . '} l WHERE l.userid = cc.userid AND l.courseid = cc.course)
                   AND EXISTS (SELECT 1 FROM {local_cpdlog_period} p
                                WHERE p.startdate <= cc.timecompleted AND p.enddate > cc.timecompleted
                                      AND p.status = :open)
                   AND NOT EXISTS (SELECT 1 FROM {' . data_deleter::TABLE . '} x
                                    WHERE x.userid = cc.userid
                                      AND (x.status = :queued OR (x.status = :done AND x.timecompleted >= cc.timecompleted)))
              ORDER BY cc.timecompleted, cc.id';
        // Completions that cannot be awarded yet (no open period covers them, or a deletion blocks them)
        // are left out here, so they never hold up later ones; the database returns one batch at most.
        $completions = $DB->get_recordset_sql($sql, [
            'fieldid' => $field->get('id'),
            'open' => \local_cpdlog\persistent\period::STATUS_OPEN,
            'queued' => data_deleter::STATUS_QUEUED,
            'done' => data_deleter::STATUS_DONE,
        ], 0, $limit);
        foreach ($completions as $completion) {
            $outcome = self::award((int) $completion->userid, (int) $completion->course, (int) $completion->timecompleted, false);
            if ($outcome === 'created') {
                $result->created++;
            } else if ($outcome === 'skipped') {
                $result->skipped++;
            }
        }
        $completions->close();
        return $result;
    }
}
