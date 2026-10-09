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
 * Post-install steps for the CPD logbook plugin.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adds the starting categories that staff can rename, disable or add to, the starting reports, and
 * the course custom fields that say which courses award CPD.
 *
 * @return bool
 */
function xmldb_local_cpdlog_install() {
    \local_cpdlog\local\setup::add_default_categories();
    \local_cpdlog\local\setup::add_default_reports();
    \local_cpdlog\local\course_cpd::setup_fields();
    return true;
}
