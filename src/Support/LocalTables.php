<?php

namespace Jma\Connect\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Which tables and columns this app's database has, for the dropdowns on the
 * mapping screens and for checking a saved mapping still fits the schema.
 */
final class LocalTables
{
    /** Framework and package bookkeeping, never attendee data. */
    private const HIDDEN = [
        'migrations', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions',
        'password_reset_tokens', 'password_resets', 'personal_access_tokens', 'notifications',
        'telescope_entries', 'telescope_entries_tags', 'telescope_monitoring', 'pulse_entries',
        'pulse_aggregates', 'pulse_values',
    ];

    /** @var array<string, list<string>> */
    private static array $columns = [];

    /**
     * @return array<string, string> name => name
     */
    public static function options(): array
    {
        // The app's own schema only: with no argument, MySQL and Postgres
        // list every database the user can see.
        $names = collect(Schema::getTables(Schema::getCurrentSchemaListing()))
            ->pluck('name')
            ->unique()
            ->reject(fn (string $name) => in_array($name, self::HIDDEN, true) || str_starts_with($name, 'connect_') || str_starts_with($name, 'sqlite_'))
            ->sort()
            ->values()
            ->all();

        return array_combine($names, $names);
    }

    /**
     * @return list<string>
     */
    public static function columns(?string $table): array
    {
        if (blank($table)) {
            return [];
        }

        if (! isset(self::$columns[$table])) {
            if (! Schema::hasTable($table)) {
                return [];
            }

            self::$columns[$table] = Schema::getColumnListing($table);
        }

        return self::$columns[$table];
    }

    /**
     * @return array<string, string>
     */
    public static function columnOptions(?string $table): array
    {
        $columns = self::columns($table);

        return array_combine($columns, $columns);
    }

    public static function flush(): void
    {
        self::$columns = [];
    }
}
