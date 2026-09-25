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
 * Admin settings and management pages for the CPD logbook plugin.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
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
