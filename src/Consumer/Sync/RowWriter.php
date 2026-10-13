<?php

namespace Jma\Connect\Consumer\Sync;

use Jma\Connect\Consumer\Models\SyncIssue;
use Jma\Connect\Consumer\Models\SyncMapping;
use Jma\Connect\Consumer\Models\SyncRun;
use Jma\Connect\Contracts\AttendeeSink;
use Jma\Connect\Data\AttendeeData;
use Jma\Connect\Support\LocalTables;
use Throwable;

/**
 * Writes attendees for one mapping, one at a time. A row that can't be
 * written is held as a SyncIssue with its payload instead of being dropped,
 * and a held row that later writes cleanly is resolved.
 */
final class RowWriter
{
    /** @var array{created: int, updated: int, deleted: int, skipped: int} */
    public array $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0];

    /** @var list<string> */
    public array $errors = [];

    public int $held = 0;

    /** @var array<string, int> remote id => issue id */
    private array $open;

    public function __construct(
        private readonly AttendeeSink $sink,
        private readonly SyncMapping $mapping,
        private readonly ?SyncRun $run = null,
    ) {
        $this->open = $mapping->openIssues()->pluck('id', 'remote_id')->all();
    }

    public function write(AttendeeData $attendee): bool
    {
        try {
            $attendee->assertValid();

            $this->counts[$this->sink->write($this->mapping, $attendee)->value]++;
        } catch (Throwable $e) {
            $this->hold($attendee, LocalTables::describe($e));

            return false;
        }

        if (isset($this->open[$attendee->id])) {
            SyncIssue::whereKey($this->open[$attendee->id])->update(['resolved_at' => now()]);
            unset($this->open[$attendee->id]);
        }

        return true;
    }

    private function hold(AttendeeData $attendee, string $reason): void
    {
        $this->held++;

        if (count($this->errors) < SyncRun::MAX_ERRORS) {
            $this->errors[] = "[{$attendee->id}] {$reason}";
        }

        $issue = SyncIssue::firstOrNew(['sync_mapping_id' => $this->mapping->id, 'remote_id' => $attendee->id]);

        $issue->fill([
            'sync_run_id' => $this->run?->id ?? $issue->sync_run_id,
            'reason' => $reason,
            'payload' => $attendee->toArray(),
            'occurrences' => $issue->exists ? $issue->occurrences + 1 : 1,
            'last_seen_at' => now(),
            'resolved_at' => null,
        ])->save();

        $this->open[$attendee->id] = $issue->id;
    }
}
