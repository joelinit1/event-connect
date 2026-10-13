<?php

namespace Jma\Connect\Consumer\Console;

use Illuminate\Console\Command;
use Jma\Connect\Consumer\Jobs\SyncMappingJob;
use Jma\Connect\Consumer\Models\SyncMapping;

class SyncCommand extends Command
{
    protected $signature = 'connect:sync
        {--connection= : Only sync mappings belonging to this connection ID}
        {--mapping= : Only sync this mapping ID}
        {--queue : Queue the runs for a worker instead of running them here}';

    protected $description = 'Sync every enabled event mapping';

    public function handle(): int
    {
        $mappings = SyncMapping::query()
            ->where('enabled', true)
            ->when($this->option('connection'), fn ($query, $id) => $query->where('connection_id', $id))
            ->when($this->option('mapping'), fn ($query, $id) => $query->where('id', $id))
            ->with('connection')
            ->get();

        if ($mappings->isEmpty()) {
            $this->info('No enabled mappings to sync.');

            return self::SUCCESS;
        }

        foreach ($mappings as $mapping) {
            $destination = $mapping->target_table ?? "local event #{$mapping->local_event_id}";
            $label = "[{$mapping->connection->name}] {$mapping->remote_event_name} → {$destination}";

            if ($this->option('queue')) {
                SyncMappingJob::dispatch($mapping);
                $this->line("Queued {$label}");

                continue;
            }

            // Run here, so syncing needs only the scheduler, not a queue worker.
            $run = SyncMappingJob::runNow($mapping);
            $this->line("{$label}: {$run?->status?->value} ({$run?->created} created, {$run?->updated} updated, {$run?->deleted} deleted)");
        }

        return self::SUCCESS;
    }
}
