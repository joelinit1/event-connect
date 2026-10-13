<?php

namespace Jma\Connect\Consumer\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Jma\Connect\Consumer\Models\SyncMapping;
use Jma\Connect\Consumer\Models\SyncRun;
use Jma\Connect\Consumer\Notifications\SyncRunNotification;
use Jma\Connect\Consumer\Sinks\TableAttendeeSink;
use Jma\Connect\Consumer\Sync\FieldReport;
use Jma\Connect\Consumer\Sync\RowWriter;
use Jma\Connect\Contracts\AttendeeSink;
use Jma\Connect\Contracts\SyncAlertRecipients;
use Jma\Connect\Data\AttendeeData;
use Jma\Connect\Data\Dates;
use Jma\Connect\Enums\SyncRunStatus;
use Jma\Connect\Support\LocalTables;
use Throwable;

/**
 * Unique only while waiting: the hourly schedule and "Sync now" can't pile
 * up duplicates in the queue, but a run already in progress never blocks a
 * fresh request (which would otherwise sit behind a stale 10-minute lock).
 *
 * $requestedBy is whoever clicked "Sync now" (null for scheduled runs): they
 * get the outcome in their notification bell. A run that fails for good, or
 * whose field report raises a warning the previous run didn't, also alerts
 * the host's SyncAlertRecipients, scheduled or not.
 *
 * Rows that can't be written are held (see SyncIssue) rather than dropped.
 */
class SyncMappingJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 600;

    /**
     * Above this many, a run that would remove more synced people than it
     * keeps removes nobody and warns instead, unless forced (Full resync).
     */
    public const REMOVAL_GUARD = 5;

    public function __construct(public SyncMapping $mapping, public ?Model $requestedBy = null, public bool $force = false)
    {
        $this->onQueue(config('connect.consumer.queue', 'default'));
    }

    /**
     * Runs a sync in this process rather than on the queue: no queue worker
     * needed, and whoever asked sees the outcome straight away. A failure
     * is recorded on the run (and alerted) rather than thrown.
     */
    public static function runNow(SyncMapping $mapping, bool $force = false): ?SyncRun
    {
        // A big first sync from a browser can outlast the web server's
        // timeout: let it finish anyway rather than stop half-way.
        @set_time_limit(0);
        ignore_user_abort(true);

        rescue(fn () => static::dispatchSync($mapping, null, $force));

        return $mapping->runs()->latest('id')->first();
    }

    public function uniqueId(): string
    {
        return (string) $this->mapping->id;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(AttendeeSink $sink): void
    {
        $mapping = $this->mapping->fresh(['connection']);

        // A long-running worker would otherwise keep column lists from
        // before someone altered a table.
        LocalTables::flush();

        // Captured before the first request so anything changed mid-run
        // falls inside the next run's window.
        $startedAt = now()->toImmutable();

        $run = $mapping->runs()->create([
            'status' => SyncRunStatus::Running,
            'started_at' => $startedAt,
        ]);

        $writer = new RowWriter($sink, $mapping, $run);
        $report = new FieldReport;

        // The built-in sink knows which rows it wrote, so it reads the whole
        // list every run and removes anyone the source no longer has: an
        // incremental read never mentions a row that was erased or stopped
        // matching the source's filter. Custom sinks keep incremental reads.
        $mirror = $sink instanceof TableAttendeeSink;
        $present = [];

        try {
            $since = $mirror ? null : $mapping->cursor?->subMinutes((int) config('connect.consumer.cursor_overlap_minutes', 5));

            foreach ($mapping->connection->source()->attendees($mapping->remote_event_id, $since) as $attendee) {
                $report->add($attendee);
                $writer->write($attendee);

                if (! $attendee->isDeleted()) {
                    $present[$attendee->id] = true;
                }
            }

            $removalWarning = $mirror ? $this->removeMissing($sink, $mapping, $writer, $present) : null;
        } catch (Throwable $e) {
            $run->update([
                ...$writer->counts,
                'status' => SyncRunStatus::Failed,
                'finished_at' => now(),
                'row_count' => $report->rows,
                'coverage' => $report->toArray(),
                'errors' => [...$writer->errors, $e->getMessage()],
            ]);

            throw $e;
        }

        $previousWarnings = $mapping->runs()->whereKeyNot($run->id)->latest('id')->first()?->warnings ?? [];
        $warnings = [...$report->warnings($mapping), ...array_filter([$removalWarning])];

        $run->update([
            ...$writer->counts,
            'status' => $writer->held === 0 ? SyncRunStatus::Succeeded : SyncRunStatus::Partial,
            'finished_at' => now(),
            'row_count' => $report->rows,
            'coverage' => $report->toArray(),
            'errors' => $writer->errors,
            'warnings' => $warnings,
        ]);

        // Advance even after row errors: those rows are held for a human to
        // fix and retry, whereas holding the cursor back would re-pull the
        // whole window forever for one bad row.
        $mapping->update([
            'cursor' => $startedAt,
            'seen_fields' => array_values(array_unique([...$mapping->seen_fields ?? [], ...array_keys($report->filled)])),
        ]);

        // New field-map warnings matter even on a scheduled run: they're
        // how a silently broken mapping gets noticed.
        $newWarnings = array_diff($warnings, $previousWarnings);

        if ($newWarnings !== []) {
            $this->notify([...$this->alertRecipients(), $this->requestedBy], $run);
        } elseif ($this->requestedBy !== null) {
            $this->notify([$this->requestedBy], $run);
        }
    }

    /**
     * Removes (per the mapping's delete setting) the synced rows whose
     * remote attendee wasn't in this complete read. Only runs after the
     * whole list was read, and refuses a removal bigger than what's kept,
     * which is likelier a misconfigured source than mass cancellations.
     *
     * @param  array<string, true>  $present
     */
    private function removeMissing(TableAttendeeSink $sink, SyncMapping $mapping, RowWriter $writer, array $present): ?string
    {
        // A destination that isn't set up held every row instead; removing
        // anything then would act on a broken mapping.
        if ($mapping->on_delete === 'ignore' || $mapping->destinationProblems() !== []) {
            return null;
        }

        $missing = array_values(array_diff($sink->syncedRemoteIds($mapping), array_map('strval', array_keys($present))));

        if ($missing === []) {
            return null;
        }

        if (! $this->force && count($missing) > self::REMOVAL_GUARD && count($missing) > count($present)) {
            return sprintf(
                'The source sent %d attendee(s), but %d synced earlier are no longer among them. To be safe, none were removed: check the source is set up right, then use Full resync to remove them.',
                count($present),
                count($missing),
            );
        }

        foreach ($missing as $remoteId) {
            $writer->write(AttendeeData::fromArray(['id' => $remoteId, 'deleted_at' => Dates::format(now()->toImmutable())]));
        }

        return null;
    }

    public function failed(?Throwable $exception): void
    {
        $this->mapping->runs()
            ->where('status', SyncRunStatus::Running)
            ->update([
                'status' => SyncRunStatus::Failed,
                'finished_at' => now(),
                'errors' => json_encode([$exception?->getMessage() ?? 'Job failed.']),
            ]);

        $run = $this->mapping->runs()->latest('id')->first();

        if ($run !== null) {
            $this->notify([...$this->alertRecipients(), $this->requestedBy], $run);
        }
    }

    /**
     * @return iterable<Model>
     */
    private function alertRecipients(): iterable
    {
        /** @var class-string<SyncAlertRecipients>|null $class */
        $class = config('connect.consumer.alert_recipients');

        return $class ? app($class)->recipients() : [];
    }

    /**
     * Never lets a notification problem fail (and so retry) the sync itself.
     *
     * @param  array<Model|null>  $users
     */
    private function notify(array $users, SyncRun $run): void
    {
        $users = collect($users)->filter()->unique(fn (Model $user) => $user::class.':'.$user->getKey());

        rescue(fn () => SyncRunNotification::make($run)->sendToDatabase($users));
    }
}
