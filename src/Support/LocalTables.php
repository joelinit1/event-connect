<?php

namespace Jma\Connect\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

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

    /** @var array<string, array{name: string, auto: bool}|null> */
    private static array $primaryKeys = [];

    /** Columns tried, in order, to label a row in a dropdown. */
    private const LABELS = ['name', 'title', 'label', 'event_name', 'slug', 'code', 'email'];

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

    /**
     * The table's single-column primary key, if it has one.
     *
     * @return array{name: string, auto: bool}|null
     */
    public static function primaryKey(string $table): ?array
    {
        if (! array_key_exists($table, self::$primaryKeys)) {
            $primary = collect(Schema::getIndexes($table))->firstWhere('primary', true);
            $name = count($primary['columns'] ?? []) === 1 ? $primary['columns'][0] : null;
            $column = $name === null ? null : collect(Schema::getColumns($table))->firstWhere('name', $name);

            self::$primaryKeys[$table] = $name === null ? null : ['name' => $name, 'auto' => (bool) ($column['auto_increment'] ?? false)];
        }

        return self::$primaryKeys[$table];
    }

    /**
     * The table and column a column points at: its foreign key, or failing
     * that the usual naming (event_id → events.id).
     *
     * @return array{table: string, column: string}|null
     */
    public static function references(?string $table, ?string $column): ?array
    {
        if (blank($table) || blank($column) || self::columns($table) === []) {
            return null;
        }

        foreach (Schema::getForeignKeys($table) as $key) {
            if ($key['columns'] === [$column]) {
                return ['table' => $key['foreign_table'], 'column' => $key['foreign_columns'][0]];
            }
        }

        $guess = Str::plural(Str::beforeLast($column, '_id'));

        return str_ends_with($column, '_id') && in_array('id', self::columns($guess), true)
            ? ['table' => $guess, 'column' => 'id']
            : null;
    }

    /**
     * Choices for a value of $column: the rows it points at, labelled by
     * name, or else the distinct values already in it.
     *
     * @return array<string, string>
     */
    public static function valueOptions(?string $table, ?string $column, int $limit = 500): array
    {
        if (! in_array($column, self::columns($table), true)) {
            return [];
        }

        if ($ref = self::references($table, $column)) {
            $label = collect(self::LABELS)->first(fn (string $c) => in_array($c, self::columns($ref['table']), true));

            return DB::table($ref['table'])
                ->orderBy($label ?? $ref['column'])
                ->limit($limit)
                ->get([$ref['column'], ...($label ? [$label] : [])])
                ->mapWithKeys(fn (object $row) => [
                    (string) $row->{$ref['column']} => $label ? "{$row->{$label}} (#{$row->{$ref['column']}})" : (string) $row->{$ref['column']},
                ])
                ->all();
        }

        return DB::table($table)->whereNotNull($column)->distinct()->orderBy($column)->limit($limit)->pluck($column)
            ->mapWithKeys(fn ($value) => [(string) $value => (string) $value])
            ->all();
    }

    /**
     * A database error as something an admin can act on, rather than the
     * driver's message with the whole SQL statement attached.
     */
    public static function describe(Throwable $e): string
    {
        if (! $e instanceof QueryException) {
            return $e->getMessage();
        }

        $message = $e->errorInfo[2] ?? $e->getPrevious()?->getMessage() ?? $e->getMessage();

        $column = match (true) {
            (bool) preg_match('/NOT NULL constraint failed: \w+\.(\w+)/', $message, $m),
            (bool) preg_match("/Field '(\w+)' doesn't have a default value/", $message, $m),
            (bool) preg_match("/Column '(\w+)' cannot be null/", $message, $m),
            (bool) preg_match('/null value in column "(\w+)"/', $message, $m) => $m[1],
            default => null,
        };

        if ($column !== null) {
            return "Column '{$column}' needs a value, but nothing writes one. Map a field to it, give its rule a default, or (for the event) set the event column. ({$message})";
        }

        if (preg_match('/UNIQUE constraint failed|Duplicate entry|duplicate key value/i', $message)) {
            return "Another row already has this value in a column that must be unique. ({$message})";
        }

        if (preg_match('/FOREIGN KEY constraint failed|foreign key constraint/i', $message)) {
            return "A value doesn't match any row in the table it points to (check the local event). ({$message})";
        }

        return $message;
    }

    public static function flush(): void
    {
        self::$columns = [];
        self::$primaryKeys = [];
        LocalModels::flush();
    }
}
