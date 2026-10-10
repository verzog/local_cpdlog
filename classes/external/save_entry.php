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
 * Web service that saves a member's CPD entry from the Moodle App, and optionally submits it.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_cpdlog\local\entry_manager;
use local_cpdlog\persistent\entry;

/**
 * Saves a member's CPD entry as a draft, with its evidence, and optionally submits it for review.
 *
 * The same rules apply as on the website's entry form: the member's own draft or rejected entry in
 * an open period, the logging rules, and evidence before submitting where it is needed.
 */
class save_entry extends external_api
{
    /** @var int Evidence value that keeps the entry's current files; Moodle's IGNORE_FILE_MERGE has the same value. */
    const KEEP_EVIDENCE = -1;

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'The entry to change, or 0 for a new entry', VALUE_DEFAULT, 0),
            'categoryid' => new external_value(PARAM_INT, 'The CPD category'),
            'courseid' => new external_value(PARAM_INT, 'The course, or -1 for an activity outside Moodle'),
            'activityname' => new external_value(PARAM_TEXT, 'Name of an activity outside Moodle', VALUE_DEFAULT, ''),
            'provider' => new external_value(PARAM_TEXT, 'Provider of an activity outside Moodle', VALUE_DEFAULT, ''),
            'activitydate' => new external_value(PARAM_ALPHANUMEXT, 'The day of the activity, as YYYY-MM-DD'),
            'hours' => new external_value(PARAM_FLOAT, 'The CPD hours'),
            'description' => new external_value(
                PARAM_RAW,
                'Plain-text description; null keeps the entry\'s current description',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
            'evidence' => new external_value(
                PARAM_INT,
                'Draft area holding all the evidence files; 0 for none; -1 keeps the current files',
                VALUE_DEFAULT,
                self::KEEP_EVIDENCE
            ),
            'submit' => new external_value(PARAM_BOOL, 'Whether to submit the entry for review', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Saves the entry.
     *
     * @param int $id The entry, or 0.
     * @param int $categoryid The category.
     * @param int $courseid The course, or -1.
     * @param string $activityname External activity name.
     * @param string $provider External activity provider.
     * @param string $activitydate YYYY-MM-DD.
     * @param float $hours The hours.
     * @param string|null $description The description, or null to keep it.
     * @param int $evidence Draft area id, 0 or -1.
     * @param bool $submit Whether to submit.
     * @return array entryid, status and message.
     */
    public static function execute(
        int $id,
        int $categoryid,
        int $courseid,
        string $activityname,
        string $provider,
        string $activitydate,
        float $hours,
        ?string $description,
        int $evidence,
        bool $submit
    ): array {
        global $DB, $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'id' => $id,
            'categoryid' => $categoryid,
            'courseid' => $courseid,
            'activityname' => $activityname,
            'provider' => $provider,
            'activitydate' => $activitydate,
            'hours' => $hours,
            'description' => $description,
            'evidence' => $evidence,
            'submit' => $submit,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/cpdlog:submit', $context);
        $userid = (int) $USER->id;

        // Ownership and state are checked here, never taken from the request.
        $entry = null;
        if ($params['id']) {
            $entry = entry::get_record(['id' => $params['id']]) ?: null;
            if (!$entry || !entry_manager::can_edit($entry, $userid)) {
                throw new \moodle_exception('error:entrylocked', 'local_cpdlog');
            }
        }

        $data = (object) [
            'categoryid' => $params['categoryid'],
            'courseid' => $params['courseid'],
            'activityname' => $params['activityname'],
            'provider' => $params['provider'],
            'activitydate' => self::parse_date($params['activitydate']),
            'hours' => $params['hours'],
            'description' => $params['description'] ?? '',
            'descriptionformat' => FORMAT_PLAIN,
        ];
        // The app edits descriptions as plain text, so one left unchanged keeps its formatting.
        if ($params['description'] === null && $entry) {
            $data->description = $entry->get('description');
            $data->descriptionformat = $entry->get('descriptionformat');
        }

        // As on the website, submitting needs evidence where the category or an external activity
        // requires it, so nothing is saved if it is missing.
        if ($params['submit'] && !entry_manager::validate($userid, $data, $entry)) {
            $external = entry_manager::is_external_data($data);
            if ($params['evidence'] === self::KEEP_EVIDENCE) {
                $files = $entry ? entry_manager::count_evidence($entry) : 0;
            } else {
                $files = entry_manager::count_draft_files($params['evidence']);
            }
            if (!$files && entry_manager::needs_evidence((int) $data->categoryid, $external)) {
                throw new \moodle_exception($external ? 'error:evidenceexternal' : 'error:evidencerequired', 'local_cpdlog');
            }
        }

        $transaction = $DB->start_delegated_transaction();
        $entry = entry_manager::save_draft($userid, $data, $entry);
        if ($params['evidence'] !== self::KEEP_EVIDENCE) {
            entry_manager::save_evidence($entry, $userid, $params['evidence']);
        }
        $message = get_string('entrysaved', 'local_cpdlog');
        if ($params['submit']) {
            entry_manager::submit($entry, $userid);
            $message = get_string('entrysubmitted', 'local_cpdlog');
        }
        $transaction->allow_commit();

        // Two activities on one day are possible, so a likely duplicate is a warning, not an error.
        if (entry_manager::count_duplicates($entry)) {
            $message .= ' ' . get_string('duplicatewarning', 'local_cpdlog');
        }
        return [
            'entryid' => (int) $entry->get('id'),
            'status' => $entry->get('status'),
            'message' => $message,
        ];
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'entryid' => new external_value(PARAM_INT, 'The saved entry'),
            'status' => new external_value(PARAM_ALPHA, 'Its status: draft or submitted'),
            'message' => new external_value(PARAM_TEXT, 'What happened, for the member'),
        ]);
    }

    /**
     * Turns a YYYY-MM-DD day into the start of that day in the site timezone, as the website's date
     * selector does.
     *
     * @param string $day The day.
     * @return int
     * @throws \invalid_parameter_exception If it is not a real date.
     */
    private static function parse_date(string $day): int {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, \core_date::get_server_timezone_object());
        if (!$date || $date->format('Y-m-d') !== $day) {
            throw new \invalid_parameter_exception('activitydate must be a date as YYYY-MM-DD');
        }
        return $date->getTimestamp();
    }
}
