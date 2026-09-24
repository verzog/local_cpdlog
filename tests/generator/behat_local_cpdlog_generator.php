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
 * Behat data generator for the CPD logbook plugin.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
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
        ];
    }
}
