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
 * Web services for the CPD logbook, used by the Moodle App.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_cpdlog_delete_entry' => [
        'classname' => \local_cpdlog\external\delete_entry::class,
        'description' => 'Deletes one of the current member\'s draft CPD entries and its evidence.',
        'type' => 'write',
        'capabilities' => 'local/cpdlog:submit',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'local_cpdlog_save_entry' => [
        'classname' => \local_cpdlog\external\save_entry::class,
        'description' => 'Saves one of the current member\'s CPD entries as a draft with its evidence, and optionally submits it.',
        'type' => 'write',
        'capabilities' => 'local/cpdlog:submit',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'local_cpdlog_submit_entry' => [
        'classname' => \local_cpdlog\external\submit_entry::class,
        'description' => 'Submits one of the current member\'s draft CPD entries for review.',
        'type' => 'write',
        'capabilities' => 'local/cpdlog:submit',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
];
