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
 * Callbacks for the CPD logbook plugin.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adds a link to the member's CPD logbook on their own profile page.
 *
 * @param \core_user\output\myprofile\tree $tree The profile tree.
 * @param stdClass $user The user whose profile is shown.
 * @param bool $iscurrentuser Whether that user is the one viewing.
 * @param stdClass|null $course The course, when shown in a course.
 */
function local_cpdlog_myprofile_navigation(\core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    if (!$iscurrentuser || !has_capability('local/cpdlog:viewown', context_system::instance())) {
        return;
    }
    $tree->add_node(new \core_user\output\myprofile\node(
        'miscellaneous',
        'local_cpdlog',
        get_string('mylogbook', 'local_cpdlog'),
        null,
        new moodle_url('/local/cpdlog/index.php')
    ));
}

/**
 * Serves evidence files, only to the entry's owner, staff who can view every logbook, and approvers.
 *
 * Only the evidence area is served. A file id alone is never enough: the entry is loaded and its
 * owner checked on every request. Files are always downloaded, never displayed, so an uploaded
 * HTML or SVG file cannot run in the site's origin.
 *
 * @param stdClass $course Unused; evidence is not in a course.
 * @param stdClass|null $cm Unused.
 * @param context $context The file's context, which must be the system context.
 * @param string $filearea The file area.
 * @param array $args The entry id, then the file path and name.
 * @param bool $forcedownload Ignored; evidence is always downloaded.
 * @param array $options Additional options for sending the file.
 * @return bool False when the file is not served.
 */
function local_cpdlog_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $USER;

    if ($context->contextlevel != CONTEXT_SYSTEM || $filearea !== \local_cpdlog\local\entry_manager::EVIDENCE_AREA) {
        return false;
    }
    require_login(null, false);

    $entryid = (int) array_shift($args);
    $entry = \local_cpdlog\persistent\entry::get_record(['id' => $entryid]);
    if (!$entry || !\local_cpdlog\local\entry_manager::can_view_evidence($entry, (int) $USER->id)) {
        return false;
    }

    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
    $file = get_file_storage()->get_file($context->id, 'local_cpdlog', $filearea, $entryid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, true, $options);
}

/**
 * Shows reporting period calendar events only to users who can view a CPD logbook.
 *
 * @param calendar_event $event The event.
 * @param int $userid The user viewing the calendar.
 * @return bool
 */
function local_cpdlog_core_calendar_is_event_visible(calendar_event $event, int $userid = 0): bool {
    return has_capability('local/cpdlog:viewown', context_system::instance(), $userid ?: null);
}
