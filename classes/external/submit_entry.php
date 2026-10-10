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
 * Web service that submits a member's draft CPD entry for review, from the Moodle App.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_cpdlog\local\entry_manager;
use local_cpdlog\persistent\entry;

/**
 * Submits a member's draft CPD entry for review, from the Moodle App.
 */
class submit_entry extends external_api
{
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'The draft entry'),
        ]);
    }

    /**
     * Acts on the draft. The entry manager checks it is the member's own draft in an open period.
     *
     * @param int $id The entry.
     * @return array message.
     */
    public static function execute(int $id): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['id' => $id]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/cpdlog:submit', $context);

        $entry = entry::get_record(['id' => $params['id']]);
        if (!$entry) {
            throw new \moodle_exception('error:entrylocked', 'local_cpdlog');
        }
        entry_manager::submit($entry, (int) $USER->id);
        return ['message' => get_string('entrysubmitted', 'local_cpdlog')];
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'message' => new external_value(PARAM_TEXT, 'What happened, for the member'),
        ]);
    }
}
