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
 * Builds the iMIS rows approved entries are pushed as.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local\push;

use local_cpdlog\local\display;
use local_cpdlog\local\import\mapping as import_mapping;
use local_cpdlog\persistent\category;
use local_cpdlog\persistent\entry;

/**
 * Turns a logbook entry into an iMIS row (GenericEntityData), from the push settings (decision 26).
 *
 * Two targets:
 * - object: a business object such as CPDLogbook (the default), with its fields named in the
 *   settings. The defaults are SCCA's CPDLogbook fields.
 * - activity: an iMIS Activity record of a set type, with every Activity column, typed, as iMIS
 *   only accepts the full record (found by local_membershipbridge).
 *
 * A reversed entry keeps its row in iMIS, with no hours and its title marked as reversed.
 */
class payload
{
    /** @var string Target: a business object. */
    const TARGET_OBJECT = 'object';

    /** @var string Target: an Activity record. */
    const TARGET_ACTIVITY = 'activity';

    /** @var string Default business object. */
    const DEFAULT_OBJECT = 'CPDLogbook';

    /** @var string[] Default object fields by what they hold, from SCCA's CPDLogbook. */
    const DEFAULT_FIELDS = [
        'member' => 'ID',
        'date' => 'ActivityDate',
        'title' => 'CourseTitle',
        'origintype' => 'OriginatingReferenceType',
        'originvalue' => 'OriginatingReferenceValue',
    ];

    /** @var string Default hours fields, one CATEGORY=field per line. */
    const DEFAULT_HOURS = "EA=EducationHours\nRP=ReviewingPerformanceHours\nMO=MeasuringOutcomesHours";

    /** @var string Default fixed values, one field=value per line. */
    const DEFAULT_EXTRA = 'SCCAActivity=1';

    /** @var string Default origin type marking rows Moodle wrote. */
    const DEFAULT_ORIGIN = 'MOODLE';

    /** @var string Default Activity type. */
    const DEFAULT_ACTIVITY_TYPE = 'CPD';

    /**
     * Returns the push target setting.
     *
     * @return string object or activity.
     */
    public static function target(): string {
        return get_config('local_cpdlog', 'pushtarget') === self::TARGET_ACTIVITY ? self::TARGET_ACTIVITY : self::TARGET_OBJECT;
    }

    /**
     * Returns the business object the rows are written to: the object setting, or Activity.
     *
     * @return string
     */
    public static function entity(): string {
        if (self::target() === self::TARGET_ACTIVITY) {
            return 'Activity';
        }
        $object = trim((string) get_config('local_cpdlog', 'pushobject'));
        return preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $object) ? $object : self::DEFAULT_OBJECT;
    }

    /**
     * Returns the origin type that marks rows Moodle wrote.
     *
     * @return string
     */
    public static function origin(): string {
        $origin = trim((string) get_config('local_cpdlog', 'pushorigin'));
        return $origin !== '' ? $origin : self::DEFAULT_ORIGIN;
    }

    /**
     * Returns the reference to an entry stored in its iMIS row.
     *
     * @param int $entryid The entry.
     * @return string
     */
    public static function reference(int $entryid): string {
        return 'cpdlog:' . $entryid;
    }

    /**
     * Returns an object field setting, or its default.
     *
     * @param string $name member, date, title, origintype or originvalue.
     * @return string The field, or '' when it is not to be written.
     */
    public static function field(string $name): string {
        $value = get_config('local_cpdlog', 'pushmap' . $name);
        return $value === false ? self::DEFAULT_FIELDS[$name] : trim((string) $value);
    }

    /**
     * Returns the hours fields by category short name.
     *
     * @return string[]
     */
    public static function hours_fields(): array {
        $value = get_config('local_cpdlog', 'pushmaphours');
        return import_mapping::parse_hours($value === false ? self::DEFAULT_HOURS : (string) $value);
    }

    /**
     * Returns the fixed values written on every row, such as SCCAActivity=1.
     *
     * @return string[] Values by field.
     */
    public static function extra(): array {
        $value = get_config('local_cpdlog', 'pushextra');
        return import_mapping::parse_hours($value === false ? self::DEFAULT_EXTRA : (string) $value);
    }

    /**
     * Returns what is missing for the settings to be usable.
     *
     * @return string[] Names of the missing settings.
     */
    public static function get_missing(): array {
        if (self::target() === self::TARGET_ACTIVITY) {
            return [];
        }
        $missing = [];
        foreach (['member', 'date'] as $name) {
            if (self::field($name) === '') {
                $missing[] = 'pushmap' . $name;
            }
        }
        if (!self::hours_fields()) {
            $missing[] = 'pushmaphours';
        }
        return $missing;
    }

    /**
     * Builds the row for an entry.
     *
     * @param entry $entry The entry.
     * @param string $memberid The member's iMIS ID.
     * @param string|null $imiskey The row's key when it exists already, to change it; null to create it.
     * @return array GenericEntityData.
     * @throws \moodle_exception If the entry's category has no hours field.
     */
    public static function build(entry $entry, string $memberid, ?string $imiskey = null): array {
        $reversed = $entry->get('status') === entry::STATUS_REVERSED;
        $hours = $reversed ? 0.0 : (float) $entry->get('hours');
        $title = \core_text::substr(display::course($entry, false), 0, $reversed ? 245 : 255);
        if ($reversed) {
            $title = get_string('push:reversedtitle', 'local_cpdlog', $title);
        }
        $date = (new \DateTimeImmutable('@' . $entry->get('activitydate')))
            ->setTimezone(\core_date::get_server_timezone_object())->format('Y-m-d\TH:i:s');
        $shortname = category::get_record(['id' => $entry->get('categoryid')])?->get('shortname');

        if (self::target() === self::TARGET_ACTIVITY) {
            return self::activity_body($entry, $memberid, $hours, $title, $date, (string) $shortname, $imiskey);
        }

        $fields = self::hours_fields();
        if (!isset($fields[$shortname])) {
            throw new \moodle_exception('push:nohoursfield', 'local_cpdlog', '', $shortname);
        }
        $values = [self::field('member') => $memberid, self::field('date') => $date];
        foreach ($fields as $categoryname => $field) {
            $values[$field] = self::typed('System.Decimal', $categoryname === $shortname ? round($hours, 2) : 0);
        }
        $labels = ['title' => $title, 'origintype' => self::origin(), 'originvalue' => self::reference((int) $entry->get('id'))];
        foreach ($labels as $name => $value) {
            if (self::field($name) !== '') {
                $values[self::field($name)] = $value;
            }
        }
        foreach (self::extra() as $field => $value) {
            $values[$field] = $value;
        }
        $keys = $imiskey === null ? [] : explode('|', $imiskey);
        return self::entity_body(self::entity(), $memberid, $values, $keys);
    }

    /**
     * Builds an Activity record, with every column as iMIS requires.
     *
     * @param entry $entry The entry.
     * @param string $memberid The member's iMIS ID.
     * @param float $hours The hours.
     * @param string $title The title.
     * @param string $date The date, as iMIS takes it.
     * @param string $category The category short name.
     * @param string|null $imiskey The record's SEQN when it exists, or null.
     * @return array
     */
    protected static function activity_body(
        entry $entry,
        string $memberid,
        float $hours,
        string $title,
        string $date,
        string $category,
        ?string $imiskey
    ): array {
        $type = trim((string) get_config('local_cpdlog', 'pushactivitytype'));
        $values = [
            'ACTION_CODES' => null,
            'ACTIVITY_TYPE' => \core_text::substr($type !== '' ? $type : self::DEFAULT_ACTIVITY_TYPE, 0, 10),
            'AMOUNT' => self::typed('System.Decimal', 0),
            'ATTACH_SEQN' => self::typed('System.Int32', 0),
            'ATTACH_TOTAL' => self::typed('System.Int32', 0),
            'BATCH_NUM' => null,
            'CAMPAIGN_CODE' => null,
            'CATEGORY' => \core_text::substr($category, 0, 15),
            'CO_ID' => null,
            'DESCRIPTION' => $title,
            'EFFECTIVE_DATE' => null,
            'GRACE_PERIOD' => self::typed('System.Int32', 0),
            'PartyId' => $memberid,
            'INTENT_TO_EDIT' => null,
            'MEM_TRIB_CODE' => null,
            'MEMBER_TYPE' => null,
            'NEXT_INSTALL_DATE' => null,
            'NOTE' => null,
            'NOTE_2' => self::reference((int) $entry->get('id')),
            'OBJECT' => null,
            'ORG_CODE' => null,
            'ORIGINATING_TRANS_NUM' => self::typed('System.Int32', 0),
            'OTHER_CODE' => null,
            'OTHER_ID' => null,
            'PAY_METHOD' => null,
            'PRODUCT_CODE' => null,
            'QUANTITY' => self::typed('System.Decimal', 0),
            'RECURRING_REQUEST' => self::typed('System.Boolean', false),
            'SOLICITOR_ID' => null,
            'SOURCE_CODE' => \core_text::substr(self::origin(), 0, 40),
            'SOURCE_SYSTEM' => null,
            'STATUS_CODE' => null,
            'TAXABLE_VALUE' => self::typed('System.Decimal', 0),
            'THRU_DATE' => $date,
            'TICKLER_DATE' => null,
            'TRANSACTION_DATE' => $date,
            'UF_1' => null,
            'UF_2' => null,
            'UF_3' => null,
            'UF_4' => self::typed('System.Decimal', round($hours, 2)),
            'UF_5' => self::typed('System.Decimal', 0),
            'UF_6' => null,
            'UF_7' => null,
            'UNITS' => self::typed('System.Decimal', round($hours, 2)),
        ];
        if ($imiskey !== null) {
            $values['SEQN'] = self::typed('System.Int32', (int) $imiskey);
        }
        return self::entity_body('Activity', $memberid, $values, $imiskey === null ? [] : [$imiskey]);
    }

    /**
     * Wraps values in a GenericEntityData body belonging to a member.
     *
     * @param string $entity The business object.
     * @param string $memberid The member's iMIS ID.
     * @param array $values Values by field; null for a field sent without a value.
     * @param string[] $keys The row's key parts when it exists already.
     * @return array
     */
    protected static function entity_body(string $entity, string $memberid, array $values, array $keys): array {
        $properties = [];
        foreach ($values as $name => $value) {
            $property = ['$type' => 'Asi.Soa.Core.DataContracts.GenericPropertyData, Asi.Contracts', 'Name' => (string) $name];
            if ($value !== null && $value !== '') {
                $property['Value'] = $value;
            }
            $properties[] = $property;
        }
        $body = [
            '$type' => 'Asi.Soa.Core.DataContracts.GenericEntityData, Asi.Contracts',
            'EntityTypeName' => $entity,
            'PrimaryParentEntityTypeName' => 'Party',
            'PrimaryParentIdentity' => self::identity('Party', [$memberid]),
            'Properties' => [
                '$type' => 'Asi.Soa.Core.DataContracts.GenericPropertyDataCollection, Asi.Contracts',
                '$values' => $properties,
            ],
        ];
        if ($keys) {
            $body['Identity'] = self::identity($entity, $keys);
        }
        return $body;
    }

    /**
     * Returns an iMIS identity.
     *
     * @param string $entity The business object.
     * @param string[] $keys The key parts.
     * @return array
     */
    protected static function identity(string $entity, array $keys): array {
        return [
            '$type' => 'Asi.Soa.Core.DataContracts.IdentityData, Asi.Contracts',
            'EntityTypeName' => $entity,
            'IdentityElements' => [
                // The backtick is part of the .NET type name; chr(96) keeps it out of the source.
                '$type' => 'System.Collections.ObjectModel.Collection' . chr(96) . '1[[System.String, mscorlib]], mscorlib',
                '$values' => array_map('strval', $keys),
            ],
        ];
    }

    /**
     * Returns a typed iMIS value.
     *
     * @param string $type The .NET type, such as System.Decimal.
     * @param mixed $value The value.
     * @return array
     */
    protected static function typed(string $type, $value): array {
        return ['$type' => $type, '$value' => $value];
    }

    /**
     * Reads the key iMIS gave a new row: its identity, else its key properties.
     *
     * @param \stdClass $reply The created row as iMIS sent it back.
     * @return string The key, parts joined with |; '' if none is found.
     */
    public static function key_from_reply(\stdClass $reply): string {
        $parts = $reply->Identity->IdentityElements->{'$values'} ?? [];
        $parts = array_values(array_filter(array_map(fn($part) => trim((string) $part), (array) $parts), 'strlen'));
        if ($parts) {
            return implode('|', $parts);
        }
        $properties = \local_cpdlog\local\imis\client::properties($reply);
        if (self::target() === self::TARGET_ACTIVITY) {
            return isset($properties['SEQN']) ? (string) $properties['SEQN'] : '';
        }
        $id = $properties[self::field('member')] ?? '';
        $ordinal = $properties['Ordinal'] ?? '';
        return $id !== '' && $ordinal !== '' ? $id . '|' . $ordinal : '';
    }
}
