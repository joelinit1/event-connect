<?php

namespace Jma\Connect\Consumer\Console;

use Illuminate\Console\Command;
use Jma\Connect\Consumer\Jobs\SyncMappingJob;
use Jma\Connect\Consumer\Models\SyncMapping;

class SyncCommand extends Command
{
    protected $signature = 'connect:sync
        {--connection= : Only queue mappings belonging to this connection ID}
        {--mapping= : Only queue this mapping ID}';

    protected $description = 'Queue a sync run for every enabled event mapping';

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
            SyncMappingJob::dispatch($mapping);

            $this->line("Queued [{$mapping->connection->name}] {$mapping->remote_event_name} → local event #{$mapping->local_event_id}");
        }

        $this->comment('A mapping already waiting in the queue is not queued a second time.');

        return self::SUCCESS;
    }
}
