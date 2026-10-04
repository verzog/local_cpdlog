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
 * Admin settings and management pages for the CPD logbook plugin.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$ADMIN->add('localplugins', new admin_category('local_cpdlog', new lang_string('pluginname', 'local_cpdlog')));

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_cpdlog_settings', new lang_string('settings', 'local_cpdlog'));
    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_configtext(
            'local_cpdlog/maxhoursperentry',
            new lang_string('maxhoursperentry', 'local_cpdlog'),
            new lang_string('maxhoursperentry_desc', 'local_cpdlog'),
            '40',
            PARAM_FLOAT
        ));
        $enabledeletion = new admin_setting_configcheckbox(
            'local_cpdlog/enabledeletion',
            new lang_string('enabledeletion', 'local_cpdlog'),
            new lang_string('enabledeletion_desc', 'local_cpdlog'),
            0
        );
        // Switching deletion off cancels queued deletions at once.
        $enabledeletion->set_updatedcallback([\local_cpdlog\local\data_deleter::class, 'setting_updated']);
        $settings->add($enabledeletion);

        // CPD hours from the image blog appear only when that plugin is installed.
        if (\local_cpdlog\local\imageblog_sync::is_installed()) {
            $settings->add(new admin_setting_heading(
                'local_cpdlog/imageblogheading',
                new lang_string('imageblog', 'local_cpdlog'),
                new lang_string('imageblogheading_desc', 'local_cpdlog')
            ));
            $settings->add(new admin_setting_configcheckbox(
                'local_cpdlog/imageblogenabled',
                new lang_string('imageblogenabled', 'local_cpdlog'),
                new lang_string('imageblogenabled_desc', 'local_cpdlog'),
                1
            ));
            $settings->add(new admin_setting_configselect(
                'local_cpdlog/imageblogcategory',
                new lang_string('imageblogcategory', 'local_cpdlog'),
                new lang_string('imageblogcategory_desc', 'local_cpdlog'),
                'EA',
                [\local_cpdlog\local\imageblog_sync::class, 'get_category_choices']
            ));
        }
    }
    $ADMIN->add('local_cpdlog', $settings);
}

// Management pages are visible to holders of local/cpdlog:manageperiods, not only site administrators.
$ADMIN->add('local_cpdlog', new admin_externalpage(
    'local_cpdlog_categories',
    new lang_string('categories', 'local_cpdlog'),
    new moodle_url('/local/cpdlog/admin/categories.php'),
    'local/cpdlog:manageperiods'
));
$ADMIN->add('local_cpdlog', new admin_externalpage(
    'local_cpdlog_periods',
    new lang_string('periods', 'local_cpdlog'),
    new moodle_url('/local/cpdlog/admin/periods.php'),
    'local/cpdlog:manageperiods'
));
// The approval queue is visible to holders of local/cpdlog:approve.
$ADMIN->add('local_cpdlog', new admin_externalpage(
    'local_cpdlog_review',
    new lang_string('approvalqueue', 'local_cpdlog'),
    new moodle_url('/local/cpdlog/admin/review.php'),
    'local/cpdlog:approve'
));
// Deleting a member's CPD data is visible to holders of local/cpdlog:deletedata.
$ADMIN->add('local_cpdlog', new admin_externalpage(
    'local_cpdlog_deletedata',
    new lang_string('deletedata', 'local_cpdlog'),
    new moodle_url('/local/cpdlog/admin/deletedata.php'),
    'local/cpdlog:deletedata'
));
