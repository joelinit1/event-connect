<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Jma\Connect\Consumer\Jobs\SyncMappingJob;
use Jma\Connect\Consumer\Models\Connection;
use Jma\Connect\Consumer\Models\SyncMapping;
use Jma\Connect\Data\AttendeeData;
use Jma\Connect\Enums\SyncRunStatus;
use Jma\Connect\Enums\WriteResult;
use Jma\Connect\Tests\Fixtures\RecordingSink;
use RuntimeException;

function mapping(?CarbonImmutable $cursor = null): SyncMapping
{
    $connection = Connection::create([
        'name' => 'LLS',
        'driver' => 'suite',
        'base_url' => 'https://source.test',
        'token' => 'secret-token',
    ]);

    return $connection->mappings()->create([
        'remote_event_id' => '7',
        'remote_event_name' => 'Spring Gala',
        'local_event_id' => 99,
        'cursor' => $cursor,
    ]);
}

function fakeAttendees(): void
{
    Http::fake([
        'source.test/api/v1/events/7/attendees*' => Http::response([
            'data' => [
                ['id' => 1, 'first_name' => 'Ada', 'last_name' => 'Lovelace'],
                ['id' => 2, 'first_name' => 'Grace', 'last_name' => 'Hopper'],
                ['id' => 3, 'first_name' => 'Mary', 'last_name' => 'Somerville', 'deleted_at' => '2026-02-01T00:00:00Z'],
            ],
            'links' => ['next' => null],
        ]),
    ]);
}

it('writes every attendee through the sink and records a successful run with counts', function () {
    fakeAttendees();
    RecordingSink::$behaviour = fn (AttendeeData $a) => $a->isDeleted() ? WriteResult::Deleted : WriteResult::Created;

    $mapping = mapping();

    SyncMappingJob::dispatchSync($mapping);

    $run = $mapping->runs()->sole();

    expect(RecordingSink::$written)->toHaveCount(3)
        ->and(RecordingSink::$written[0]['mapping']->is($mapping))->toBeTrue()
        ->and($run->status)->toBe(SyncRunStatus::Succeeded)
        ->and($run->created)->toBe(2)
        ->and($run->deleted)->toBe(1)
        ->and($run->errors)->toBe([])
        ->and($run->finished_at)->not->toBeNull()
        ->and($mapping->fresh()->cursor?->equalTo($run->started_at))->toBeTrue();
});

it('sends updated_since with the overlap when a cursor exists', function () {
    fakeAttendees();
    config()->set('connect.consumer.cursor_overlap_minutes', 5);

    SyncMappingJob::dispatchSync(mapping(CarbonImmutable::parse('2026-03-01T12:00:00Z')));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'updated_since=2026-03-01T11%3A55%3A00%2B00%3A00'));
});

it('records row errors as a partial run but still advances the cursor', function () {
    fakeAttendees();
    RecordingSink::$behaviour = function (AttendeeData $a) {
        if ($a->id === '2') {
            throw new RuntimeException('No table named X');
        }

        return WriteResult::Updated;
    };

    $mapping = mapping();

    SyncMappingJob::dispatchSync($mapping);

    $run = $mapping->runs()->sole();

    expect($run->status)->toBe(SyncRunStatus::Partial)
        ->and($run->updated)->toBe(2)
        ->and($run->errors)->toBe(['[2] No table named X'])
        ->and($mapping->fresh()->cursor)->not->toBeNull();
});

it('marks the run failed, keeps the cursor and rethrows when the source is unreachable', function () {
    Http::fake(['source.test/*' => Http::response(['message' => 'Server error'], 500)]);

    $mapping = mapping();

    expect(fn () => SyncMappingJob::dispatchSync($mapping))->toThrow(RequestException::class);

    $run = $mapping->runs()->sole();

    expect($run->status)->toBe(SyncRunStatus::Failed)
        ->and($run->errors)->toHaveCount(1)
        ->and($mapping->fresh()->cursor)->toBeNull()
        ->and(RecordingSink::$written)->toBe([]);
});

it('records a nameless attendee as a row error without passing it to the sink', function () {
    Http::fake([
        'source.test/api/v1/events/7/attendees*' => Http::response([
            'data' => [
                ['id' => 1, 'first_name' => 'Ada', 'last_name' => 'Lovelace'],
                ['id' => 2, 'first_name' => '', 'last_name' => 'Hopper'],
                ['id' => 3, 'deleted_at' => '2026-02-01T00:00:00Z'],
            ],
            'links' => ['next' => null],
        ]),
    ]);
    RecordingSink::$behaviour = fn (AttendeeData $a) => $a->isDeleted() ? WriteResult::Deleted : WriteResult::Created;

    $mapping = mapping();

    SyncMappingJob::dispatchSync($mapping);

    $run = $mapping->runs()->sole();

    expect(collect(RecordingSink::$written)->pluck('attendee.id')->all())->toBe(['1', '3'])
        ->and($run->status)->toBe(SyncRunStatus::Partial)
        ->and($run->created)->toBe(1)
        ->and($run->deleted)->toBe(1)
        ->and($run->errors)->toBe(['[2] Missing required first_name.']);
});
