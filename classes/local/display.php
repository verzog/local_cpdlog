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
 * Shared formatting for CPD entries on the logbook and approval pages.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local;

use local_cpdlog\persistent\entry;
use local_cpdlog\persistent\period;

/**
 * Shared formatting for CPD entries and progress on the logbook and approval pages.
 */
final class display
{
    /**
     * Returns the activity date as DD/MM/YYYY. Dates are whole days in the site timezone.
     *
     * @param entry $entry The entry.
     * @return string
     */
    public static function activity_date(entry $entry): string {
        $dateformat = get_string('strftimedatefull', 'local_cpdlog');
        return userdate($entry->get('activitydate'), $dateformat, \core_date::get_server_timezone(), false);
    }

    /**
     * Returns the member's description of the activity, formatted for display.
     *
     * The description editor allows no embedded files, so there are no file URLs to rewrite.
     *
     * @param entry $entry The entry.
     * @return string HTML.
     */
    public static function description(entry $entry): string {
        return format_text(
            (string) $entry->get('description'),
            $entry->get('descriptionformat'),
            ['context' => \context_system::instance()]
        );
    }

    /**
     * Returns download links for an entry's evidence files, one per line.
     *
     * @param entry $entry The entry.
     * @return string HTML.
     */
    public static function evidence_links(entry $entry): string {
        $links = [];
        foreach (entry_manager::get_evidence_files($entry) as $file) {
            $url = \moodle_url::make_pluginfile_url(
                \context_system::instance()->id,
                'local_cpdlog',
                entry_manager::EVIDENCE_AREA,
                $entry->get('id'),
                '/',
                $file->get_filename(),
                true
            );
            $links[] = \html_writer::link($url, s($file->get_filename()));
        }
        return implode(\html_writer::empty_tag('br'), $links);
    }

    /**
     * Returns the template context for a member's progress in a period.
     *
     * @param int $userid The member.
     * @param period $period The period.
     * @return array Context for the local_cpdlog/progress template.
     */
    public static function progress(int $userid, period $period): array {
        $hours = progress::get_hours_by_category($userid, $period->get('id'));
        $totals = (object) [
            'approved' => format_float(array_sum($hours['approved']), 2),
            'pending' => format_float(array_sum($hours['pending']), 2),
        ];
        $totalskey = $hours['pending'] ? 'progresstotalspending' : 'progresstotals';

        $targets = [];
        foreach (progress::get_progress($userid, $period->get('id')) as $row) {
            $a = (object) ['approved' => format_float($row->approved, 2), 'required' => format_float($row->required, 2)];
            $targets[] = [
                // Mustache escapes the text, so it is formatted without escaping here.
                'name' => format_string($row->target->get('name'), true, ['escape' => false]),
                'summary' => get_string('progresstarget', 'local_cpdlog', $a),
                'pending' => $row->pending > 0 ? get_string('progresspending', 'local_cpdlog', format_float($row->pending, 2)) : '',
                'percent' => $row->percent,
                'met' => $row->met,
            ];
        }

        return [
            'heading' => get_string('progressfor', 'local_cpdlog', format_string($period->get('name'), true, ['escape' => false])),
            'totals' => get_string($totalskey, 'local_cpdlog', $totals),
            'hastargets' => (bool) $targets,
            'targets' => $targets,
        ];
    }
}
