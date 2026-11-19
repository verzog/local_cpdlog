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
 * Upcoming CPD from more than one source.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local\upcoming;

/**
 * Combines the items of several sources, soonest first.
 */
class combined_source implements source
{
    /** @var source[] The sources. */
    protected array $sources;

    /**
     * Creates a source combining others.
     *
     * @param source[] $sources The sources.
     */
    public function __construct(array $sources) {
        $this->sources = $sources;
    }

    /**
     * Returns every source's items for the member, soonest first.
     *
     * @param int $userid The member.
     * @param int $from The start of the window.
     * @param int $to The end of the window.
     * @return \stdClass[]
     */
    public function get_items(int $userid, int $from, int $to): array {
        $items = [];
        foreach ($this->sources as $source) {
            array_push($items, ...$source->get_items($userid, $from, $to));
        }
        usort($items, fn($a, $b) => [$a->timestart, $a->title] <=> [$b->timestart, $b->title]);
        return $items;
    }
}
