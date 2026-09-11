<?php

namespace Jma\Connect\Consumer\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Jma\Connect\Consumer\Models\SyncMapping;
use Jma\Connect\Consumer\Models\SyncRun;
use Jma\Connect\Contracts\AttendeeSink;
use Jma\Connect\Enums\SyncRunStatus;
use Throwable;

class SyncMappingJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(public SyncMapping $mapping)
    {
        $this->onQueue(config('connect.consumer.queue', 'default'));
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

        // Captured before the first request so anything changed mid-run
        // falls inside the next run's window.
        $startedAt = now()->toImmutable();

        $run = $mapping->runs()->create([
            'status' => SyncRunStatus::Running,
            'started_at' => $startedAt,
        ]);

        $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0];
        $errors = [];

        try {
            $since = $mapping->cursor?->subMinutes((int) config('connect.consumer.cursor_overlap_minutes', 5));

            foreach ($mapping->connection->source()->attendees($mapping->remote_event_id, $since) as $attendee) {
                try {
                    $counts[$sink->write($mapping, $attendee)->value]++;
                } catch (Throwable $e) {
                    if (count($errors) < SyncRun::MAX_ERRORS) {
                        $errors[] = "[{$attendee->id}] {$e->getMessage()}";
                    }
                }
            }
        } catch (Throwable $e) {
            $run->update([
                ...$counts,
                'status' => SyncRunStatus::Failed,
                'finished_at' => now(),
                'errors' => [...$errors, $e->getMessage()],
            ]);

            throw $e;
        }

        $run->update([
            ...$counts,
            'status' => $errors === [] ? SyncRunStatus::Succeeded : SyncRunStatus::Partial,
            'finished_at' => now(),
            'errors' => $errors,
        ]);

        // Advance even after row errors: they're recorded on the run for a
        // human to act on, whereas holding the cursor back would re-pull
        // the whole window forever for one bad row.
        $mapping->update(['cursor' => $startedAt]);
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
    }
}
