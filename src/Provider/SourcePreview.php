<?php

namespace Jma\Connect\Provider;

use Jma\Connect\Consumer\Sync\FieldReport;
use Jma\Connect\Data\AttendeeData;
use Jma\Connect\Mapping\FieldMap;
use Jma\Connect\Mapping\Suggest;
use Jma\Connect\Provider\Models\AttendeeSource;

/**
 * What an attendee source would send, built from its first few live rows,
 * plus anything about its mapping worth a human's attention. Feeds the
 * connect::preview view.
 */
final class SourcePreview
{
    /**
     * @return array{title: string, problems: list<string>, warnings: list<string>, notes: list<string>, columns: list<string>, rows: list<array<string, mixed>>, coverage: list<array{field: string, filled: int, total: int, target: ?string}>}
     */
    public static function build(AttendeeSource $source, int $limit = 10): array
    {
        $view = ['title' => "What {$source->name} sends", 'problems' => $source->configurationProblems(), 'warnings' => [], 'notes' => [], 'columns' => [], 'rows' => [], 'coverage' => []];

        if ($view['problems'] !== []) {
            return $view;
        }

        $raw = $source->attendeeRows(null)->limit($limit)->get()->map(fn (object $row) => (array) $row);
        $attendees = $raw->map(fn (array $row) => $source->toAttendee($row));
        $map = $source->fieldMap();
        $report = new FieldReport;
        $attendees->each(fn (AttendeeData $a) => $report->add($a));

        if ($raw->isEmpty()) {
            $view['warnings'][] = 'No live rows match this source yet, so there is nothing to preview. Check the table and filter.';
        }

        if (! self::writes($source, 'first_name')) {
            $view['problems'][] = 'Nothing is mapped to first_name. The receiving app holds any attendee without one, so every row would be held.';
        } elseif (($blank = $attendees->filter(fn (AttendeeData $a) => trim($a->firstName) === '')->count()) > 0) {
            $view['warnings'][] = "{$blank} of {$attendees->count()} previewed rows have no first name and would be held by the receiving app.";
        }

        foreach ($map->sources() as $path) {
            if ($raw->isNotEmpty() && $raw->every(fn (array $row) => ! FieldMap::read($row, $path)[0])) {
                $view['warnings'][] = "'{$path}' is mapped, but none of the previewed rows has it.";
            }
        }

        $used = [...array_map(fn (string $p) => explode('.', $p)[0], $map->sources()),
            $source->id_column, $source->filter_column, $source->updated_at_column, $source->deleted_at_column];
        $unsent = array_values(array_filter(
            $source->columns(),
            fn (string $c) => ! in_array($c, $used, true) && ! Suggest::isSensitive($c) && ! in_array($c, ['created_at', 'updated_at', 'deleted_at'], true),
        ));

        if ($unsent !== []) {
            $view['notes'][] = 'Columns not sent: '.implode(', ', $unsent).'. Map any the receiving app needs; leave the rest.';
        }

        $view['columns'] = ['id', ...array_keys($report->filled)];
        $view['rows'] = $attendees->map(fn (AttendeeData $a) => ['id' => $a->id, ...$a->values()])->all();
        $view['coverage'] = collect($report->filled)
            ->map(fn (int $filled, string $field) => ['field' => $field, 'filled' => $filled, 'total' => $report->rows, 'target' => null])
            ->values()->all();

        return $view;
    }

    private static function writes(AttendeeSource $source, string $key): bool
    {
        return collect($source->fieldMap()->rules())->contains(fn (array $rule) => $rule['to'] === $key);
    }
}
