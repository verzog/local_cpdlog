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
 * Adhoc task that carries out a queued deletion of a member's CPD data.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\task;

use local_cpdlog\local\data_deleter;

/**
 * Adhoc task that carries out a queued deletion of a member's CPD data.
 *
 * Custom data: deletionid, the register id. If the task fails, the deletion stays queued and Moodle
 * retries the task later.
 */
class delete_member_data extends \core\task\adhoc_task
{
    /**
     * Returns the task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:deletememberdata', 'local_cpdlog');
    }

    /**
     * Carries out the deletion.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        data_deleter::run((int) ($data->deletionid ?? 0));
    }
}
