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
 * Upcoming CPD shown on the logbook: what members are taking part in, and what is available.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local;

use local_cpdlog\local\upcoming\moodle_source;
use local_cpdlog\local\upcoming\source;

/**
 * Upcoming CPD shown on the logbook, split into the member's own and what else is available.
 *
 * The source is kept behind its own class (decision 8), so another one, such as iMIS, can replace
 * Moodle later, and the whole feature can be switched off.
 */
class upcoming
{
    /** @var string Items the member is already taking part in. */
    const FILTER_MINE = 'mine';

    /** @var string Items the member could join. */
    const FILTER_AVAILABLE = 'available';

    /** @var int Default number of days ahead to look. */
    const DEFAULT_DAYS = 60;

    /** @var int Default number of items in each list. */
    const DEFAULT_LIMIT = 10;

    /**
     * Whether the panel is shown. The setting defaults to on; unset counts as on.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        $enabled = get_config('local_cpdlog', 'upcomingenabled');
        return $enabled === false || (bool) $enabled;
    }

    /**
     * Returns how many days ahead to look, from the settings.
     *
     * @return int
     */
    public static function get_days(): int {
        $days = (int) get_config('local_cpdlog', 'upcomingdays');
        return $days > 0 ? min($days, 366) : self::DEFAULT_DAYS;
    }

    /**
     * Returns how many items each list shows, from the settings.
     *
     * @return int
     */
    public static function get_limit(): int {
        $limit = (int) get_config('local_cpdlog', 'upcominglimit');
        return $limit > 0 ? min($limit, 50) : self::DEFAULT_LIMIT;
    }

    /**
     * Returns the source of upcoming CPD.
     *
     * @return source
     */
    public static function get_source(): source {
        return new moodle_source();
    }

    /**
     * Returns the window to look in: from the start of today, for the set number of calendar days,
     * in the site timezone, so daylight saving does not move its ends.
     *
     * @param int $now The current time.
     * @return int[] [from, to]
     */
    public static function get_window(int $now): array {
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone(\core_date::get_server_timezone_object())->setTime(0, 0);
        return [$today->getTimestamp(), $today->modify('+' . self::get_days() . ' days')->getTimestamp()];
    }

    /**
     * Returns the member's two lists, ready to show: their own, and what else is available.
     *
     * Items that started earlier today are left in, so an event today stays listed all day.
     *
     * @param int $userid The member.
     * @param int|null $now The current time; now if null.
     * @return array[] Keyed mine and available; each a list of items as in source, plus date (text)
     *                 and iscourse.
     */
    public static function get_lists(int $userid, ?int $now = null): array {
        [$from, $to] = self::get_window($now ?? time());
        $lists = [self::FILTER_MINE => [], self::FILTER_AVAILABLE => []];
        $limit = self::get_limit();
        foreach (self::get_source()->get_items($userid, $from, $to) as $item) {
            $filter = $item->mine ? self::FILTER_MINE : self::FILTER_AVAILABLE;
            if (count($lists[$filter]) >= $limit) {
                continue;
            }
            $format = get_string($item->allday ? 'strftimedatefull' : 'strftimedatetimefull', 'local_cpdlog');
            $lists[$filter][] = [
                'type' => $item->type,
                'iscourse' => $item->type === 'course',
                'title' => $item->title,
                'coursename' => $item->coursename,
                // Days and hours keep their leading zeros: DD/MM/YYYY, as elsewhere in the logbook.
                'date' => userdate($item->timestart, $format, 99, false, false),
                'hours' => $item->hours,
                'category' => $item->category,
                'url' => $item->url,
            ];
        }
        return $lists;
    }
}
