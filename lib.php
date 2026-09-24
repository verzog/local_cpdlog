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
