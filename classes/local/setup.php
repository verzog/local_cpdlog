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
 * Starting data for the CPD logbook plugin.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local;

use core_reportbuilder\datasource;
use core_reportbuilder\local\helpers\report;
use core_reportbuilder\local\models\report as report_model;
use local_cpdlog\persistent\category;
use local_cpdlog\reportbuilder\audience\cpdstaff;
use local_cpdlog\reportbuilder\datasource\entries;
use local_cpdlog\reportbuilder\datasource\progress as progress_source;

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

    /** @var string[] Report Builder sources that get a starting report, mapped to the report name string ids. */
    const DEFAULT_REPORTS = [
        entries::class => 'report:entriesdefault',
        progress_source::class => 'report:progressdefault',
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

    /**
     * Adds a starting custom report for each CPD report source that has none, shown to CPD staff.
     *
     * Each report uses its source's default columns and filters, and has the CPD staff audience, so
     * holders of local/cpdlog:viewall find it under Reports. Staff can copy or change them freely.
     * Safe to run more than once: a source that already has a custom report is skipped.
     */
    public static function add_default_reports(): void {
        foreach (self::DEFAULT_REPORTS as $source => $stringid) {
            $params = ['source' => $source, 'type' => datasource::TYPE_CUSTOM_REPORT];
            if (report_model::record_exists_select('source = :source AND type = :type', $params)) {
                continue;
            }
            $created = report::create_report((object) [
                'name' => get_string($stringid, 'local_cpdlog'),
                'source' => $source,
            ]);
            cpdstaff::create($created->get('id'), []);
        }
    }
}
