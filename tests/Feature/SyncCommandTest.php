<?php

use Illuminate\Support\Facades\Queue;
use Jma\Connect\Consumer\Jobs\SyncMappingJob;
use Jma\Connect\Consumer\Models\Connection;
use Jma\Connect\Consumer\Models\SyncMapping;

beforeEach(function () {
    Queue::fake();

    $connection = Connection::create(['name' => 'LLS', 'base_url' => 'https://source.test', 'token' => 't']);

    $this->enabled = $connection->mappings()->create(['remote_event_id' => '1', 'remote_event_name' => 'A', 'local_event_id' => 10]);
    $this->alsoEnabled = $connection->mappings()->create(['remote_event_id' => '2', 'remote_event_name' => 'B', 'local_event_id' => 11]);
    $this->disabled = $connection->mappings()->create(['remote_event_id' => '3', 'remote_event_name' => 'C', 'local_event_id' => 12, 'enabled' => false]);
});

it('queues one job per enabled mapping', function () {
    $this->artisan('connect:sync')->assertSuccessful();

    Queue::assertPushed(SyncMappingJob::class, 2);
    Queue::assertNotPushed(SyncMappingJob::class, fn (SyncMappingJob $job) => $job->mapping->is($this->disabled));
});

it('can be limited to a single mapping', function () {
    $this->artisan('connect:sync', ['--mapping' => $this->alsoEnabled->id])->assertSuccessful();

    Queue::assertPushed(SyncMappingJob::class, 1);
    Queue::assertPushed(SyncMappingJob::class, fn (SyncMappingJob $job) => $job->mapping->is($this->alsoEnabled));
});

it('does not queue the same mapping twice while one is already waiting', function () {
    $this->artisan('connect:sync', ['--mapping' => $this->enabled->id])->assertSuccessful();
    $this->artisan('connect:sync', ['--mapping' => $this->enabled->id])->assertSuccessful();

    Queue::assertPushed(SyncMappingJob::class, 1);
});

it('reports when there is nothing to sync', function () {
    SyncMapping::query()->update(['enabled' => false]);

    $this->artisan('connect:sync')
        ->expectsOutputToContain('No enabled mappings')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});
