<?php

namespace Jma\Connect\Consumer\Sync;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Jma\Connect\Connect;
use Jma\Connect\Consumer\Models\SyncMapping;
use Jma\Connect\Consumer\Sinks\TableAttendeeSink;
use Jma\Connect\Contracts\AttendeeSink;
use Jma\Connect\Data\AttendeeData;
use Jma\Connect\Enums\WriteResult;
use Jma\Connect\Mapping\FieldMap;
use Jma\Connect\Support\LocalTables;
use Throwable;

/**
 * For one event mapping: what the first few remote attendees would become
 * locally (preview), and how the latest run's fields measured up (report).
 * Both feed the connect::preview view.
 */
final class MappingPreview
{
    /**
     * @return array<string, mixed>
     */
    public static function preview(SyncMapping $mapping, int $limit = 10): array
    {
        $view = self::empty("Preview: {$mapping->remote_event_name}");

        try {
            $attendees = collect($mapping->connection->source()->attendees($mapping->remote_event_id, null)->take($limit)->all());
        } catch (Throwable $e) {
            $view['problems'][] = 'Couldn\'t read from the source: '.$e->getMessage();

            return $view;
        }

        $view['problems'] = Connect::usesBuiltInSink() ? $mapping->destinationProblems() : [];
        $ready = $view['problems'] === [] && Connect::usesBuiltInSink();
        $report = new FieldReport;
        $attendees->each(fn (AttendeeData $a) => $report->add($a));

        $results = $ready ? self::trialWrite($mapping, $attendees) : [];

        $view['rows'] = $attendees->map(function (AttendeeData $attendee) use ($mapping, $results) {
            $row = ['remote id' => $attendee->id, ...$mapping->fieldMap()->apply($attendee->toArray())];
            $row['→ result'] = $results[$attendee->id] ?? '—';

            try {
                $attendee->assertValid();
            } catch (Throwable $e) {
                $row['→ result'] = 'held: '.$e->getMessage();
            }

            return array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, $row);
        })->all();

        if ($ready && $results !== []) {
            $view['notes'][] = 'Each result comes from really writing the row to '
                .(TableAttendeeSink::modelFor($mapping) ?? "table '{$mapping->target_table}'")
                .' and then undoing it, so "create" and "update" will work for real. No emails, notifications or jobs were sent.';
        }

        $view['columns'] = collect($view['rows'])->flatMap(fn (array $row) => array_keys($row))->unique()
            ->sortBy(fn (string $c) => $c === 'remote id' ? 0 : ($c === '→ result' ? 2 : 1))->values()->all();

        if ($attendees->isEmpty()) {
            $view['warnings'][] = 'The source has no attendees for this event yet, so there is nothing to preview.';
        }

        return self::withFields($view, $mapping, $report);
    }

    /**
     * The latest run's field counts, judged against the current field map.
     *
     * @return array<string, mixed>
     */
    public static function report(SyncMapping $mapping): array
    {
        $view = self::empty("Field report: {$mapping->remote_event_name}");
        $run = $mapping->runs()->whereNotNull('coverage')->latest('id')->first();

        $view['problems'] = Connect::usesBuiltInSink() ? $mapping->destinationProblems() : [];

        if ($run === null) {
            $view['notes'][] = 'No run has recorded field counts yet. Use Preview to check the mapping before the first sync.';

            return $view;
        }

        $report = new FieldReport;
        $report->rows = $run->coverage['rows'] ?? 0;
        $report->filled = $run->coverage['fields'] ?? [];

        $view['notes'][] = "From run #{$run->id} ({$run->started_at?->diffForHumans()}): {$report->rows} live attendee(s) received.";
        $view['warnings'] = $run->warnings ?? [];
        $open = $mapping->openIssues()->count();

        if ($open > 0) {
            $view['problems'][] = "{$open} row(s) are held. See Held rows to read why, then fix the mapping and retry them.";
        }

        return self::withFields($view, $mapping, $report, withWarnings: false);
    }

    /**
     * Field paths the remote source sends for this event, from a small
     * sample plus everything earlier runs saw. Drives mapping suggestions.
     *
     * @return list<string>
     */
    public static function remoteFields(SyncMapping $mapping): array
    {
        $sampled = Cache::remember("connect:remote-fields:{$mapping->connection_id}:{$mapping->remote_event_id}", 60, function () use ($mapping) {
            $report = new FieldReport;

            foreach ($mapping->connection->source()->attendees($mapping->remote_event_id, null)->take(25) as $attendee) {
                $report->add($attendee);
            }

            return array_keys($report->filled);
        });

        return array_values(array_unique([...$sampled, ...$mapping->seen_fields ?? []]));
    }

    /**
     * @param  array<string, mixed>  $view
     * @return array<string, mixed>
     */
    private static function withFields(array $view, SyncMapping $mapping, FieldReport $report, bool $withWarnings = true): array
    {
        $map = $mapping->fieldMap();

        if ($withWarnings) {
            // Judged as a first run: "new field" drift means nothing in a preview.
            $fresh = (clone $mapping)->forceFill(['seen_fields' => null]);
            $view['warnings'] = [...$view['warnings'], ...$report->warnings($fresh)];
        }

        $targets = [];

        foreach ($map->rules() as $rule) {
            foreach ((new FieldMap([$rule]))->sources() as $path) {
                $targets[$path][] = $rule['to'];
            }
        }

        $view['coverage'] = collect($report->filled)->map(function (int $filled, string $field) use ($targets, $report) {
            $to = collect($targets)->first(fn ($cols, string $path) => $field === $path || str_starts_with($field, $path.'.'));

            return ['field' => $field, 'filled' => $filled, 'total' => $report->rows, 'target' => $to ? implode(', ', $to) : null];
        })->values()->all();

        $unmapped = collect($view['coverage'])->where('target', null)->where('filled', '>', 0)->pluck('field')->all();

        if ($unmapped !== [] && ! $map->isEmpty()) {
            $view['notes'][] = 'Sent by the source but not mapped to any column: '.implode(', ', $unmapped).'.';
        }

        return $view;
    }

    /**
     * Writes each attendee with the real sink inside a transaction that is
     * always rolled back, so the result reflects the app's model hooks and
     * the table's constraints rather than a guess. Mail, notifications and
     * jobs are faked meanwhile, so the app's hooks can't send anything.
     *
     * @param  Collection<int, AttendeeData>  $attendees
     * @return array<string, string> remote id => result
     */
    private static function trialWrite(SyncMapping $mapping, Collection $attendees): array
    {
        $sink = app(AttendeeSink::class);
        $results = [];
        $real = [Mail::getFacadeRoot(), Notification::getFacadeRoot(), Bus::getFacadeRoot(), Queue::getFacadeRoot()];

        Mail::fake();
        Notification::fake();
        Bus::fake();
        Queue::fake();
        DB::beginTransaction();

        try {
            foreach ($attendees as $attendee) {
                try {
                    // A savepoint per row, as a failed statement aborts the
                    // whole transaction on Postgres.
                    $results[$attendee->id] = match (DB::transaction(fn () => $sink->write($mapping, $attendee))) {
                        WriteResult::Created => 'create',
                        WriteResult::Updated => 'update',
                        WriteResult::Deleted => 'delete',
                        WriteResult::Skipped => 'no change',
                    };
                } catch (Throwable $e) {
                    $results[$attendee->id] = 'held: '.LocalTables::describe($e);
                }
            }
        } finally {
            DB::rollBack();
            Mail::swap($real[0]);
            Notification::swap($real[1]);
            Bus::swap($real[2]);
            Queue::swap($real[3]);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private static function empty(string $title): array
    {
        return ['title' => $title, 'problems' => [], 'warnings' => [], 'notes' => [], 'columns' => [], 'rows' => [], 'coverage' => []];
    }
}
