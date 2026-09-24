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
 * Post-install steps for the CPD logbook plugin.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

/**
 * Adds starting categories that staff can rename, disable or add to.
 *
 * These are the RACGP CPD activity types, pending confirmation of the categories SCCA uses.
 *
 * @return bool
 */
function xmldb_local_cpdlog_install() {
    $shortnames = ['EA' => 'educationalactivities', 'RP' => 'reviewingperformance', 'MO' => 'measuringoutcomes'];
    $sortorder = 0;
    foreach ($shortnames as $shortname => $stringid) {
        $category = new \local_cpdlog\persistent\category(0, (object) [
            'name' => get_string('defaultcategory:' . $stringid, 'local_cpdlog'),
            'shortname' => $shortname,
            'sortorder' => $sortorder++,
        ]);
        $category->create();
    }
    return true;
}
