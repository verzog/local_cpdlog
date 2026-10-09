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
 * Keeps reporting period dates in the Moodle calendar.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local;

use local_cpdlog\persistent\period;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/calendar/lib.php');

/**
 * Keeps reporting period dates in the Moodle calendar.
 *
 * Each period has two site events owned by this plugin: one on its first day ("opens") and one on
 * its last day ("closes", the CPD deadline). They are kept in step by the period itself, so creating,
 * changing or deleting a period creates, moves or removes its events. Because the plugin owns them,
 * nobody can edit or delete them by hand in the calendar.
 */
final class calendar
{
    /** @var string Marks the event on a period's first day. */
    const OPENS = 'opens';

    /** @var string Marks the event on a period's last day. */
    const CLOSES = 'closes';

    /**
     * Creates or updates a period's two calendar events.
     *
     * @param period $period The period.
     */
    public static function sync_period(period $period): void {
        $dates = [self::OPENS => (int) $period->get('startdate'), self::CLOSES => $period->get_lastday()];
        foreach ($dates as $which => $timestart) {
            $data = (object) [
                // The calendar formats event names when it shows them, so the raw name is stored.
                'name' => get_string('calendar:period' . $which, 'local_cpdlog', $period->get('name')),
                'description' => '',
                'format' => FORMAT_HTML,
                'courseid' => SITEID,
                'categoryid' => 0,
                'groupid' => 0,
                'userid' => 0,
                'modulename' => '',
                'instance' => (int) $period->get('id'),
                'component' => 'local_cpdlog',
                'eventtype' => 'site',
                'uuid' => self::uuid((int) $period->get('id'), $which),
                'timestart' => $timestart,
                'timeduration' => 0,
                'visible' => 1,
            ];
            $existing = self::get_event((int) $period->get('id'), $which);
            if ($existing) {
                $existing->update($data, false);
            } else {
                \calendar_event::create($data, false);
            }
        }
    }

    /**
     * Removes a period's calendar events.
     *
     * @param int $periodid The period.
     */
    public static function remove_period(int $periodid): void {
        foreach ([self::OPENS, self::CLOSES] as $which) {
            if ($event = self::get_event($periodid, $which)) {
                $event->delete();
            }
        }
    }

    /**
     * Creates or updates the calendar events of every period.
     */
    public static function sync_all(): void {
        foreach (period::get_records() as $period) {
            self::sync_period($period);
        }
    }

    /**
     * Returns one of a period's calendar events, if it exists.
     *
     * @param int $periodid The period.
     * @param string $which OPENS or CLOSES.
     * @return \calendar_event|null
     */
    public static function get_event(int $periodid, string $which): ?\calendar_event {
        global $DB;
        $id = $DB->get_field('event', 'id', [
            'component' => 'local_cpdlog',
            'instance' => $periodid,
            'uuid' => self::uuid($periodid, $which),
        ]);
        return $id ? \calendar_event::load($id) : null;
    }

    /**
     * Returns the identifier stored in a period event's uuid, which tells the two events apart.
     *
     * @param int $periodid The period.
     * @param string $which OPENS or CLOSES.
     * @return string
     */
    private static function uuid(int $periodid, string $which): string {
        return 'local_cpdlog-period-' . $periodid . '-' . $which;
    }
}
