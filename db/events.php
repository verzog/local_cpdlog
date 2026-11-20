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
 * Event observers of the CPD logbook.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Internal: the push is queued in the same transaction as the approval or reversal, so both happen or neither.
$observers = [
    [
        'eventname' => '\local_cpdlog\event\entry_approved',
        'callback' => '\local_cpdlog\observer::entry_approved',
        'internal' => true,
    ],
    [
        'eventname' => '\local_cpdlog\event\entry_reversed',
        'callback' => '\local_cpdlog\observer::entry_reversed',
        'internal' => true,
    ],
];
