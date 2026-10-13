<?php

namespace Jma\Connect\Consumer\Sync;

use Jma\Connect\Consumer\Models\SyncMapping;
use Jma\Connect\Data\AttendeeData;
use Jma\Connect\Mapping\FieldMap;

/**
 * Counts, per field, how many live attendees in a run arrived with a value.
 * A field that drops to zero, or one the source starts sending that nobody
 * mapped, is a mis-mapping no row error would ever reveal.
 */
final class FieldReport
{
    public int $rows = 0;

    /** @var array<string, int> wire path => rows with a value */
    public array $filled = [];

    public function add(AttendeeData $attendee): void
    {
        if ($attendee->isDeleted()) {
            return;
        }

        $this->rows++;

        foreach ($attendee->values() as $path => $value) {
            $this->filled[$path] ??= 0;

            if (! FieldMap::isBlank(is_string($value) ? trim($value) : $value)) {
                $this->filled[$path]++;
            }
        }
    }

    /**
     * @return array{rows: int, fields: array<string, int>}
     */
    public function toArray(): array
    {
        ksort($this->filled);

        return ['rows' => $this->rows, 'fields' => $this->filled];
    }

    /**
     * What a human should look at, judged against the mapping's field map and
     * the fields it had seen before this run.
     *
     * @return list<string>
     */
    public function warnings(SyncMapping $mapping): array
    {
        if ($this->rows === 0) {
            return [];
        }

        $map = $mapping->fieldMap();
        $warnings = [];
        $rows = $this->rows === 1 ? '1 row' : "{$this->rows} rows";

        foreach ($map->rules() as $rule) {
            foreach (self::wirePaths($rule['from']) as $path) {
                $seen = $this->seen($path);

                if ($seen === null) {
                    $warnings[] = "'{$path}' is mapped to '{$rule['to']}', but the source didn't send it in any of {$rows}.";
                } elseif ($seen === 0 && $rule['default'] === null) {
                    $warnings[] = "'{$path}' (mapped to '{$rule['to']}') was empty in all {$rows}.";
                }
            }
        }

        $known = $mapping->seen_fields;

        if ($known !== null && ! $map->isEmpty()) {
            foreach (array_keys($this->filled) as $path) {
                if (! in_array($path, $known, true) && ! $this->isMapped($map, $path) && $this->filled[$path] > 0) {
                    $warnings[] = "The source started sending '{$path}', which isn't mapped to anything.";
                }
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * Rows with a value for a path, counting anything under it; null when
     * the path never arrived at all.
     */
    private function seen(string $path): ?int
    {
        $matches = array_filter(
            $this->filled,
            fn (string $key) => $key === $path || str_starts_with($key, $path.'.'),
            ARRAY_FILTER_USE_KEY,
        );

        return $matches === [] ? null : max($matches);
    }

    private function isMapped(FieldMap $map, string $path): bool
    {
        foreach ($map->sources() as $source) {
            if ($path === $source || str_starts_with($path, $source.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Paths a rule reads, as they appear in a run's field counts.
     *
     * @return list<string>
     */
    private static function wirePaths(string $from): array
    {
        return (new FieldMap([['from' => $from, 'to' => 'x']]))->sources();
    }
}
