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
 * CPD history from an uploaded CSV file.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local\import;

/**
 * CPD history from a CSV file, from any system: the first line names the columns.
 */
class csv_source implements source
{
    /** @var string[] The column names. */
    protected array $fields = [];

    /** @var array[] The rows, each as column => value. */
    protected array $rows = [];

    /**
     * Reads a CSV file's content.
     *
     * @param string $content The file's content, UTF-8, comma-separated, with a header line.
     * @throws \moodle_exception If it has no header line.
     */
    public function __construct(string $content) {
        // A byte order mark from a spreadsheet would end up in the first column's name.
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        $header = fgetcsv($handle, null, ',', '"', '');
        if (!$header || $header === [null]) {
            fclose($handle);
            throw new \moodle_exception('import:csvempty', 'local_cpdlog');
        }
        $this->fields = array_map(fn($name) => trim((string) $name), $header);
        while (($line = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($line === [null]) {
                continue;
            }
            $row = [];
            foreach ($this->fields as $index => $name) {
                $row[$name] = isset($line[$index]) ? trim((string) $line[$index]) : '';
            }
            $this->rows[] = $row;
        }
        fclose($handle);
    }

    /**
     * Returns the source's name.
     *
     * @return string
     */
    public function get_name(): string {
        return 'csv';
    }

    /**
     * Returns the column names from the header line.
     *
     * @return string[]
     */
    public function get_fields(): array {
        return $this->fields;
    }

    /**
     * Returns every row; the audit picks out one member's after mapping.
     *
     * @param string $memberid Not used: a file cannot be asked for one member.
     * @return array[]
     */
    public function get_rows(string $memberid = ''): array {
        unset($memberid);
        return $this->rows;
    }
}
