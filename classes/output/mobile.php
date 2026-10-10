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
 * Moodle App screens for the CPD logbook.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\output;

use local_cpdlog\local\display;
use local_cpdlog\local\entry_manager;
use local_cpdlog\persistent\category;
use local_cpdlog\persistent\entry;
use local_cpdlog\persistent\period;

/**
 * Builds the CPD logbook screens for the Moodle App (see db/mobile.php).
 *
 * The app calls these through tool_mobile_get_content, which does no access checks of its own, so
 * each method checks the member's capabilities itself.
 */
class mobile
{
    /** @var string[] Ionic colour for each entry status. */
    const STATUS_COLOURS = [
        entry::STATUS_DRAFT => 'medium',
        entry::STATUS_SUBMITTED => 'warning',
        entry::STATUS_APPROVED => 'success',
        entry::STATUS_REJECTED => 'danger',
        entry::STATUS_REVERSED => 'dark',
    ];

    /**
     * Hides the main menu item from users who have no CPD logbook.
     *
     * @param array $args Arguments from the app.
     * @return array
     */
    public static function mobile_init(array $args): array {
        unset($args);
        return [
            'templates' => [],
            'javascript' => '',
            'disabled' => !has_capability('local/cpdlog:viewown', \context_system::instance()),
        ];
    }

    /**
     * The member's logbook: progress in a period, and their entries with actions for drafts.
     *
     * @param array $args Arguments from the app: periodid (optional) chooses the period shown.
     * @return array
     */
    public static function mobile_logbook(array $args): array {
        global $OUTPUT, $USER;
        $context = \context_system::instance();
        \core_external\external_api::validate_context($context);
        require_capability('local/cpdlog:viewown', $context);
        $userid = (int) $USER->id;
        $cansubmit = has_capability('local/cpdlog:submit', $context);

        // As on the website: the chosen period, else the current one, else the latest to have started.
        $periods = period::get_records([], 'startdate', 'DESC');
        $shown = null;
        foreach ($periods as $period) {
            if ((int) $period->get('id') === (int) ($args['periodid'] ?? 0)) {
                $shown = $period;
            }
        }
        if (!$shown && $periods) {
            $started = array_filter($periods, fn(period $period) => $period->get('startdate') <= time());
            $shown = entry_manager::find_period(time()) ?? (reset($started) ?: reset($periods));
        }
        $periodoptions = [];
        foreach ($periods as $period) {
            $periodoptions[] = [
                'id' => (int) $period->get('id'),
                'name' => format_string($period->get('name'), true, ['escape' => false]),
            ];
        }

        $categories = [];
        foreach (category::get_records() as $category) {
            $categories[$category->get('id')] = format_string($category->get('name'), true, ['escape' => false]);
        }
        $entries = [];
        foreach (entry::get_records_select('userid = :userid', ['userid' => $userid], 'activitydate DESC, id DESC') as $entry) {
            $id = (int) $entry->get('id');
            $status = $entry->get('status');
            $editable = $cansubmit && entry_manager::can_edit($entry, $userid);
            $missing = $editable && $status === entry::STATUS_DRAFT && entry_manager::is_missing_evidence($entry);
            $reason = '';
            if ($status === entry::STATUS_REJECTED) {
                $reason = get_string('rejectionreasonis', 'local_cpdlog', $entry->get('rejectionreason'));
            } else if ($status === entry::STATUS_REVERSED) {
                $reason = get_string('reversalreasonis', 'local_cpdlog', $entry->get('reversalreason'));
            }
            $entries[] = [
                'id' => $id,
                'date' => display::activity_date($entry),
                'category' => $categories[$entry->get('categoryid')] ?? '',
                'activity' => display::course($entry, false),
                'hours' => format_float($entry->get('hours'), 2),
                'status' => get_string('entrystatus:' . $status, 'local_cpdlog'),
                'colour' => self::STATUS_COLOURS[$status],
                'source' => $entry->is_moodle_owned() ? '' : get_string('from' . $entry->get('source'), 'local_cpdlog'),
                'reason' => $reason,
                'files' => self::evidence_files($entry),
                'canedit' => $editable,
                'cansubmit' => $editable && $status === entry::STATUS_DRAFT && !$missing,
                'candelete' => $editable && $status === entry::STATUS_DRAFT,
                'evidenceneeded' => $missing,
            ];
        }

        // Text that members or staff typed goes in otherdata, which the app shows through Angular
        // interpolation; written into the template it would be compiled as Angular code.
        return [
            'templates' => [[
                'id' => 'main',
                'html' => $OUTPUT->render_from_template('local_cpdlog/mobileapp/logbook', ['cansubmit' => $cansubmit]),
            ]],
            'javascript' => file_get_contents(__DIR__ . '/../../mobileapp/logbook.js'),
            'otherdata' => [
                'periodid' => json_encode($shown ? (int) $shown->get('id') : 0),
                'periods' => json_encode($periodoptions),
                'progress' => json_encode($shown ? display::progress($userid, $shown) : null),
                'entries' => json_encode($entries),
            ],
        ];
    }

    /**
     * The form to log a new CPD activity, or edit a draft or rejected entry.
     *
     * @param array $args Arguments from the app: id (optional) is the entry to edit.
     * @return array
     */
    public static function mobile_entry_form(array $args): array {
        global $OUTPUT, $USER;
        $context = \context_system::instance();
        \core_external\external_api::validate_context($context);
        require_capability('local/cpdlog:submit', $context);
        $userid = (int) $USER->id;

        $entry = null;
        if (!empty($args['id'])) {
            $entry = entry::get_record(['id' => (int) $args['id']]) ?: null;
            if (!$entry || !entry_manager::can_edit($entry, $userid)) {
                throw new \moodle_exception('error:entrylocked', 'local_cpdlog');
            }
        }

        // The same choices as the website's entry form.
        $categories = [];
        $externalallowed = $entry && $entry->is_external();
        foreach (category::get_records([], 'sortorder') as $category) {
            if ($category->get('enabled') || ($entry && (int) $entry->get('categoryid') === (int) $category->get('id'))) {
                $categories[] = [
                    'id' => (int) $category->get('id'),
                    'name' => format_string($category->get('name'), true, ['escape' => false]),
                ];
                $externalallowed = $externalallowed || ($category->get('enabled') && $category->get('allowexternal'));
            }
        }
        $courses = entry_manager::get_course_options($userid);
        if ($entry && $entry->get('courseid') && !isset($courses[$entry->get('courseid')])) {
            $courses[$entry->get('courseid')] = format_string((string) $entry->get('coursename'));
        }
        $courseoptions = [];
        foreach ($courses as $courseid => $name) {
            $courseoptions[] = ['id' => (int) $courseid, 'name' => html_entity_decode($name, ENT_QUOTES, 'UTF-8')];
        }

        $timezone = \core_date::get_server_timezone_object();
        $values = [
            'id' => $entry ? (int) $entry->get('id') : 0,
            'categoryid' => $entry ? (int) $entry->get('categoryid') : null,
            'courseid' => null,
            'activityname' => $entry ? (string) $entry->get('activityname') : '',
            'provider' => $entry ? (string) $entry->get('provider') : '',
            'activitydate' => (new \DateTimeImmutable('@' . ($entry ? $entry->get('activitydate') : time())))
                ->setTimezone($timezone)->format('Y-m-d'),
            'hours' => $entry ? (float) $entry->get('hours') : null,
            'description' => $entry ? self::plain_description($entry) : '',
        ];
        if ($entry) {
            $values['courseid'] = $entry->is_external() ? entry::EXTERNAL_COURSE : (int) $entry->get('courseid');
        }

        $data = [
            'externalallowed' => $externalallowed,
            'externalcourse' => entry::EXTERNAL_COURSE,
            'maxfiles' => entry_manager::MAX_EVIDENCE_FILES,
            'maxbytes' => (int) entry_manager::evidence_options()['maxbytes'],
            'acceptedtypes' => implode(',', entry_manager::EVIDENCE_TYPES),
        ];
        return [
            'templates' => [[
                'id' => 'main',
                'html' => $OUTPUT->render_from_template('local_cpdlog/mobileapp/entry_form', $data),
            ]],
            'javascript' => file_get_contents(__DIR__ . '/../../mobileapp/entry_form.js'),
            'otherdata' => [
                'entry' => json_encode($values),
                'categories' => json_encode($categories),
                'courses' => json_encode($courseoptions),
                'files' => json_encode($entry ? self::evidence_files($entry) : []),
                'strings' => json_encode([
                    'offline' => get_string('mobile:offline', 'local_cpdlog'),
                    'saving' => get_string('mobile:saving', 'local_cpdlog'),
                ]),
            ],
        ];
    }

    /**
     * Returns an entry's evidence files in the form the app's file components expect.
     *
     * @param entry $entry The entry.
     * @return array[] One per file: filename, filepath, filesize, fileurl, mimetype and timemodified.
     */
    private static function evidence_files(entry $entry): array {
        $files = [];
        foreach (entry_manager::get_evidence_files($entry) as $file) {
            $files[] = [
                'filename' => $file->get_filename(),
                'filepath' => $file->get_filepath(),
                'filesize' => (int) $file->get_filesize(),
                'fileurl' => \moodle_url::make_webservice_pluginfile_url(
                    \context_system::instance()->id,
                    'local_cpdlog',
                    entry_manager::EVIDENCE_AREA,
                    $entry->get('id'),
                    $file->get_filepath(),
                    $file->get_filename()
                )->out(false),
                'mimetype' => $file->get_mimetype(),
                'timemodified' => (int) $file->get_timemodified(),
            ];
        }
        return $files;
    }

    /**
     * Returns an entry's description as plain text, as the app edits it.
     *
     * @param entry $entry The entry.
     * @return string
     */
    private static function plain_description(entry $entry): string {
        $description = (string) $entry->get('description');
        if ((int) $entry->get('descriptionformat') === FORMAT_PLAIN) {
            return $description;
        }
        return trim(html_to_text(format_text($description, $entry->get('descriptionformat')), 0, false));
    }
}
