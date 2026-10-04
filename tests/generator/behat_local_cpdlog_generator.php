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
 * Behat data generator for the CPD logbook plugin.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Behat data generator for the CPD logbook plugin.
 */
class behat_local_cpdlog_generator extends behat_generator_base
{
    /**
     * Lists the entities Behat scenarios can create.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'periods' => [
                'singular' => 'period',
                'datagenerator' => 'period',
                'required' => ['name', 'firstday', 'lastday'],
            ],
            'targets' => [
                'singular' => 'target',
                'datagenerator' => 'target',
                'required' => ['period', 'name', 'requiredhours'],
            ],
            'entries' => [
                'singular' => 'entry',
                'datagenerator' => 'entry',
                'required' => ['user', 'period'],
            ],
            'image blog awards' => [
                'singular' => 'image blog award',
                'datagenerator' => 'imageblog_award',
                'required' => ['user'],
            ],
            'deleted accounts' => [
                'singular' => 'deleted account',
                'datagenerator' => 'deleted_account',
                'required' => ['user'],
            ],
        ];
    }
}
