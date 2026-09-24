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
 * Starting data for the CPD logbook plugin.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\local;

use local_cpdlog\persistent\category;

/**
 * Starting data for the CPD logbook plugin.
 */
final class setup
{
    /** @var string[] Starting category short names mapped to their name string ids. */
    const DEFAULT_CATEGORIES = [
        'EA' => 'defaultcategory:educationalactivities',
        'RP' => 'defaultcategory:reviewingperformance',
        'MO' => 'defaultcategory:measuringoutcomes',
    ];

    /**
     * Adds any starting category that does not exist yet, after the existing categories.
     *
     * These are the RACGP CPD activity types, pending confirmation of the categories SCCA uses.
     * Safe to run more than once: categories are matched on short name.
     */
    public static function add_default_categories(): void {
        foreach (self::DEFAULT_CATEGORIES as $shortname => $stringid) {
            if (category::record_exists_select('shortname = :shortname', ['shortname' => $shortname])) {
                continue;
            }
            $category = new category(0, (object) [
                'name' => get_string($stringid, 'local_cpdlog'),
                'shortname' => $shortname,
                'sortorder' => category::next_sortorder(),
            ]);
            $category->create();
        }
    }
}
