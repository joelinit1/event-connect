<?php

namespace Jma\Connect\Consumer\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Jma\Connect\Consumer\Models\SyncMapping;
use Jma\Connect\Consumer\Models\SyncRun;
use Jma\Connect\Consumer\Notifications\SyncRunNotification;
use Jma\Connect\Contracts\AttendeeSink;
use Jma\Connect\Contracts\SyncAlertRecipients;
use Jma\Connect\Enums\SyncRunStatus;
use Throwable;

/**
 * Unique only while waiting: the hourly schedule and "Sync now" can't pile
 * up duplicates in the queue, but a run already in progress never blocks a
 * fresh request (which would otherwise sit behind a stale 10-minute lock).
 *
 * $requestedBy is whoever clicked "Sync now" (null for scheduled runs): they
 * get the outcome in their notification bell. A run that fails for good also
 * alerts the host's SyncAlertRecipients, scheduled or not.
 */
class SyncMappingJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(public SyncMapping $mapping, public ?Model $requestedBy = null)
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
                    $attendee->assertValid();

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

        if ($this->requestedBy !== null) {
            $this->notify([$this->requestedBy], $run);
        }
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
