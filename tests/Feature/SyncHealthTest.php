<?php

use Jma\Connect\Consumer\Models\Connection;
use Jma\Connect\Consumer\Models\SyncMapping;
use Jma\Connect\Enums\SyncHealth;
use Jma\Connect\Enums\SyncRunStatus;

function healthMapping(Connection $connection, bool $enabled = true): SyncMapping
{
    return $connection->mappings()->create([
        'remote_event_id' => (string) fake()->unique()->randomNumber(),
        'remote_event_name' => 'Spring Gala',
        'local_event_id' => 99,
        'enabled' => $enabled,
    ]);
}

function healthConnection(): Connection
{
    return Connection::create(['name' => 'LLS', 'driver' => 'suite', 'base_url' => 'https://source.test', 'token' => 'secret']);
}

function runFinished(SyncMapping $mapping, SyncRunStatus $status, int $minutesAgo): void
{
    $mapping->runs()->create([
        'status' => $status,
        'started_at' => now()->subMinutes($minutesAgo + 1),
        'finished_at' => now()->subMinutes($minutesAgo),
    ]);
}

beforeEach(fn () => config()->set('connect.consumer.stale_after_minutes', 120));

it('is healthy when the last successful run is inside the window, even with no cursor', function () {
    $mapping = healthMapping(healthConnection());
    runFinished($mapping, SyncRunStatus::Succeeded, 30);

    expect($mapping->fresh()->health())->toBe(SyncHealth::Healthy)
        ->and($mapping->fresh()->lastSyncedAt())->not->toBeNull();
});

it('is overdue when it has never synced or the last success is older than the window', function () {
    $never = healthMapping(healthConnection());
    $stale = healthMapping($never->connection);
    runFinished($stale, SyncRunStatus::Succeeded, 121);

    expect($never->health())->toBe(SyncHealth::Overdue)
        ->and($stale->fresh()->health())->toBe(SyncHealth::Overdue);
});

it('is failing when the latest run failed or had row errors, despite a recent success', function (SyncRunStatus $status) {
    $mapping = healthMapping(healthConnection());
    runFinished($mapping, SyncRunStatus::Succeeded, 30);
    runFinished($mapping, $status, 5);

    expect($mapping->fresh()->health())->toBe(SyncHealth::Failing);
})->with([SyncRunStatus::Failed, SyncRunStatus::Partial]);

it('is off when the mapping is disabled, whatever its runs say', function () {
    $mapping = healthMapping(healthConnection(), enabled: false);

    expect($mapping->health())->toBe(SyncHealth::Off);
});

it('reports a connection as its worst mapping and its most recent sync', function () {
    $connection = healthConnection();
    $healthy = healthMapping($connection);
    runFinished($healthy, SyncRunStatus::Succeeded, 10);
    $stale = healthMapping($connection);
    runFinished($stale, SyncRunStatus::Succeeded, 300);
    healthMapping($connection, enabled: false);

    $connection = $connection->fresh(['mappings.latestRun', 'mappings.latestSuccessfulRun']);

    expect($connection->health())->toBe(SyncHealth::Overdue)
        ->and($connection->lastSyncedAt()->equalTo($healthy->fresh()->lastSyncedAt()))->toBeTrue()
        ->and(healthConnection()->health())->toBe(SyncHealth::Off);
});
