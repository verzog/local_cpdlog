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
 * Which source column holds what, for importing CPD history.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local\import;

/**
 * Maps a source row onto the logbook, from the import settings.
 *
 * One source row can hold hours in several categories (as iMIS's EducationPoints,
 * ReviewingPerformancePoints and MeasuringOutcomesPoints do); each category with hours becomes
 * its own logbook entry. Column names are matched ignoring case, spaces and underscores.
 */
class mapping
{
    /** @var string[] The settings naming single columns, by what they hold. */
    const COLUMNS = [
        'member' => 'importmapmember',
        'key' => 'importmapkey',
        'date' => 'importmapdate',
        'title' => 'importmaptitle',
        'provider' => 'importmapprovider',
        'description' => 'importmapdescription',
    ];

    /** @var string[] The columns that must be set. */
    const REQUIRED = ['member', 'key', 'date'];

    /** @var string[] Column names by what they hold; '' when not mapped. */
    public array $columns = [];

    /** @var string[] Hours columns by logbook category short name. */
    public array $hours = [];

    /**
     * Returns the mapping from the import settings.
     *
     * @return self
     */
    public static function get(): self {
        $mapping = new self();
        foreach (self::COLUMNS as $name => $setting) {
            $mapping->columns[$name] = trim((string) get_config('local_cpdlog', $setting));
        }
        $mapping->hours = self::parse_hours((string) get_config('local_cpdlog', 'importmaphours'));
        return $mapping;
    }

    /**
     * Reads the hours setting: one CATEGORY=column per line, such as EA=EducationPoints.
     *
     * @param string $text The setting.
     * @return string[] Columns by category short name.
     */
    public static function parse_hours(string $text): array {
        $hours = [];
        foreach (preg_split('/\R/', $text) as $line) {
            if (preg_match('/^\s*([^=\s]+)\s*=\s*(\S.*?)\s*$/', $line, $match)) {
                $hours[$match[1]] = $match[2];
            }
        }
        return $hours;
    }

    /**
     * Returns what is missing for the mapping to be usable.
     *
     * @return string[] Names of the missing settings; empty when complete.
     */
    public function get_missing(): array {
        $missing = [];
        foreach (self::REQUIRED as $name) {
            if ($this->columns[$name] === '') {
                $missing[] = self::COLUMNS[$name];
            }
        }
        if (!$this->hours) {
            $missing[] = 'importmaphours';
        }
        return $missing;
    }

    /**
     * Maps one source row.
     *
     * @param array $row The row, column => value.
     * @return \stdClass memberid, key, date (start of the day in the site timezone, or null if it
     *                   cannot be read), title, provider, description and hours (by category short
     *                   name; only positive amounts).
     */
    public function map(array $row): \stdClass {
        $values = [];
        foreach ($row as $column => $value) {
            $values[self::normalise((string) $column)] = $value;
        }
        $get = fn(string $column) => $column === '' ? '' : trim((string) ($values[self::normalise($column)] ?? ''));

        $hours = [];
        foreach ($this->hours as $shortname => $column) {
            $value = str_replace(',', '.', $get($column));
            if (is_numeric($value) && (float) $value > 0) {
                $hours[$shortname] = round((float) $value, 2);
            }
        }
        return (object) [
            'memberid' => $get($this->columns['member']),
            'key' => $get($this->columns['key']),
            'date' => self::parse_date($get($this->columns['date'])),
            'title' => \core_text::substr($get($this->columns['title']), 0, 255),
            'provider' => \core_text::substr($get($this->columns['provider']), 0, 255),
            'description' => $get($this->columns['description']),
            'hours' => $hours,
        ];
    }

    /**
     * Reads a date as the start of that day in the site timezone, as the entry form stores it.
     *
     * Accepts YYYY-MM-DD (with or without a time, as iMIS sends) and DD/MM/YYYY.
     *
     * @param string $value The date.
     * @return int|null
     */
    public static function parse_date(string $value): ?int {
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $match)) {
            [, $year, $month, $day] = $match;
        } else if (preg_match('~^(\d{1,2})/(\d{1,2})/(\d{4})$~', $value, $match)) {
            [, $day, $month, $year] = $match;
        } else {
            return null;
        }
        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }
        $date = (new \DateTimeImmutable('now', \core_date::get_server_timezone_object()))
            ->setDate((int) $year, (int) $month, (int) $day)->setTime(0, 0);
        return $date->getTimestamp();
    }

    /**
     * Normalises a column name for matching: lower case, without spaces or underscores.
     *
     * @param string $column The name.
     * @return string
     */
    protected static function normalise(string $column): string {
        return strtolower(str_replace([' ', '_'], '', $column));
    }
}
