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
 * Callbacks for the CPD logbook plugin.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
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
 * Serves evidence files, only to the entry's owner or to staff who can view every logbook.
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
