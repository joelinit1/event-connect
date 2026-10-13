<?php

namespace Jma\Connect\Mapping;

use Jma\Connect\Data\AttendeeData;

/**
 * First-draft field maps from column names, so an admin reviews a mapping
 * rather than typing one. Matching is by name only; nothing is guessed from
 * values except whether a column holds JSON.
 */
final class Suggest
{
    /**
     * Groups of names that mean the same thing. The first entry is the wire
     * key (or, for keys the wire format has no named field for, the key used
     * under `extra`).
     */
    public const ALIASES = [
        'first_name' => ['first_name', 'firstname', 'forename', 'given_name', 'givenname', 'fname', 'first'],
        'last_name' => ['last_name', 'lastname', 'surname', 'family_name', 'familyname', 'lname', 'last'],
        'full_name' => ['full_name', 'fullname', 'name', 'guest_name', 'attendee_name', 'display_name', 'contact_name'],
        'email' => ['email', 'email_address', 'emailaddress', 'e_mail', 'mail', 'email_addr', 'contact_email'],
        'company' => ['company', 'company_name', 'organisation', 'organization', 'organisation_name', 'organization_name', 'org', 'employer', 'business', 'firm'],
        'job_title' => ['job_title', 'jobtitle', 'position', 'job', 'job_role', 'role', 'designation'],
        'table' => ['table', 'table_id', 'table_no', 'table_number', 'table_name', 'tableno', 'table_num'],
        'seat' => ['seat', 'seat_no', 'seat_number', 'seat_id'],
        'zone' => ['zone', 'area', 'room', 'section', 'hall'],
        'booking_ref' => ['booking_ref', 'booking_reference', 'booking_id', 'booking_no', 'order_ref', 'order_reference', 'order_id', 'reference', 'ref'],
        'category' => ['category', 'guest_type', 'ticket_type', 'attendee_type', 'guest_category', 'tier', 'type'],
        'check_in_code' => ['check_in_code', 'checkin_code', 'qr_code', 'barcode', 'ticket_code', 'access_code'],
        'checked_in_at' => ['checked_in_at', 'checkin_at', 'checked_in', 'arrived_at', 'attended_at', 'check_in_time'],
        'phone' => ['phone', 'mobile', 'telephone', 'phone_number', 'mobile_number', 'tel', 'cell'],
        'dietary' => ['dietary', 'dietary_requirements', 'dietary_requirement', 'diet', 'allergies', 'dietary_needs'],
    ];

    /** Never offered as a mapping, whatever the table calls it. */
    public const NEVER = ['password', 'remember_token', 'api_token', 'two_factor_secret', 'two_factor_recovery_codes', 'token', 'secret'];

    /** Bookkeeping columns, configured separately or not attendee data at all. */
    public const BOOKKEEPING = ['id', 'created_at', 'updated_at', 'deleted_at', 'email_verified_at'];

    /**
     * Provider side: local table columns → wire keys.
     *
     * @param  list<string>  $columns
     * @param  array<string, mixed>|null  $sample  One row, to spot JSON columns
     * @param  list<string>  $skip  Columns already used as id/filter/timestamps
     * @return list<array{from: string, to: string, transform: ?string, default: null}>
     */
    public static function forProvider(array $columns, ?array $sample = null, array $skip = []): array
    {
        $rules = [];
        $taken = [];
        $fullName = null;

        foreach ($columns as $column) {
            if (in_array($column, $skip, true) || self::isBookkeeping($column) || self::isSensitive($column)) {
                continue;
            }

            $group = self::groupOf($column);

            if ($group === 'full_name') {
                $fullName ??= $column;

                continue;
            }

            if ($group !== null && ! isset($taken[$group])) {
                $taken[$group] = true;
                $rules[] = self::rule($column, self::wireKey($group), $group === 'checked_in_at' ? Transform::Date->value : null);

                continue;
            }

            if ($sample !== null && self::isJsonObject($sample[$column] ?? null)) {
                $rules[] = self::rule($column.'.*', 'extra');
            }
        }

        if ($fullName !== null && ! isset($taken['first_name'])) {
            $rules[] = self::rule($fullName, 'first_name', Transform::FirstWord->value);
            $rules[] = self::rule($fullName, 'last_name', Transform::RestWords->value);
        }

        return $rules;
    }

    /**
     * Consumer side: wire paths (e.g. 'email', 'extra.dietary') → local columns.
     *
     * @param  list<string>  $wirePaths
     * @param  list<string>  $columns
     * @param  list<string>  $skip  Columns already used as key/event columns
     * @return list<array{from: string, to: string, transform: ?string, default: null}>
     */
    public static function forConsumer(array $wirePaths, array $columns, array $skip = []): array
    {
        $rules = [];
        $used = [];
        $available = array_values(array_filter(
            $columns,
            fn (string $column) => ! in_array($column, $skip, true) && ! self::isBookkeeping($column) && ! self::isSensitive($column),
        ));

        foreach ($wirePaths as $path) {
            $key = str_starts_with($path, 'extra.') ? substr($path, 6) : $path;
            $group = self::groupOf($key);

            $match = collect($available)->first(fn (string $column) => ! isset($used[$column]) && (
                self::normalise($column) === self::normalise($key)
                || ($group !== null && self::groupOf($column) === $group)
            ));

            if ($match !== null) {
                $used[$match] = true;
                $rules[] = self::rule($path, $match);
            }
        }

        // A single name column locally, first/last on the wire: join them.
        $nameColumn = collect($available)->first(fn (string $c) => ! isset($used[$c]) && self::groupOf($c) === 'full_name');

        if ($nameColumn !== null && in_array('first_name', $wirePaths, true)) {
            $rules[] = self::rule('{first_name} {last_name}', $nameColumn);
        }

        return $rules;
    }

    public static function groupOf(string $name): ?string
    {
        $normal = self::normalise($name);

        foreach (self::ALIASES as $group => $names) {
            foreach ($names as $alias) {
                if ($normal === $alias || str_replace('_', '', $normal) === str_replace('_', '', $alias)) {
                    return $group;
                }
            }
        }

        return null;
    }

    public static function isSensitive(string $column): bool
    {
        return in_array(self::normalise($column), self::NEVER, true);
    }

    private static function isBookkeeping(string $column): bool
    {
        return in_array(self::normalise($column), self::BOOKKEEPING, true);
    }

    private static function wireKey(string $group): string
    {
        return in_array($group, AttendeeData::WIRE_FIELDS, true) ? $group : "extra.{$group}";
    }

    private static function normalise(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($name)), '_');
    }

    private static function isJsonObject(mixed $value): bool
    {
        if (is_array($value)) {
            return ! array_is_list($value);
        }

        if (! is_string($value) || ! str_starts_with(ltrim($value), '{')) {
            return false;
        }

        return is_array(json_decode($value, true));
    }

    /**
     * @return array{from: string, to: string, transform: ?string, default: null}
     */
    private static function rule(string $from, string $to, ?string $transform = null): array
    {
        return ['from' => $from, 'to' => $to, 'transform' => $transform, 'default' => null];
    }
}
