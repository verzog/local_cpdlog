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
 * Where CPD history is imported from.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local\import;

/**
 * A source of CPD history: iMIS now, any other system later (decision 25).
 *
 * A source only hands over rows of named columns. Which column holds what is set once in the
 * import mapping (see mapping), so every source is audited and imported the same way.
 */
interface source
{
    /**
     * Returns the source's name: imis or csv.
     *
     * @return string
     */
    public function get_name(): string;

    /**
     * Returns the column names the source offers, to help staff fill in the mapping.
     *
     * @return string[]
     */
    public function get_fields(): array;

    /**
     * Returns the source's rows, each as column => value.
     *
     * @param string $memberid Only this member's rows, by their ID in the source, if the source can
     *                         choose; '' for everyone. Rows are filtered again after mapping.
     * @return array[]
     */
    public function get_rows(string $memberid = ''): array;
}
