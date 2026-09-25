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
 * Shared formatting for CPD entries on the logbook and approval pages.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\local;

use local_cpdlog\persistent\entry;

/**
 * Shared formatting for CPD entries on the logbook and approval pages.
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
}
