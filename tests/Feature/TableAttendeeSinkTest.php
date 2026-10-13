<?php

use Filament\Notifications\DatabaseNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Jma\Connect\Consumer\Jobs\SyncMappingJob;
use Jma\Connect\Consumer\Models\Connection;
use Jma\Connect\Consumer\Models\SyncIssue;
use Jma\Connect\Consumer\Models\SyncMapping;
use Jma\Connect\Consumer\Sync\RetryHeldRows;
use Jma\Connect\Enums\SyncHealth;
use Jma\Connect\Enums\SyncRunStatus;
use Jma\Connect\Tests\Fixtures\StubAlertRecipients;
use Jma\Connect\Tests\Fixtures\Tables;
use Jma\Connect\Tests\Fixtures\User;

beforeEach(function () {
    config()->set('connect.consumer.sink', null);
    Tables::consumerGuests();
});

function guestMapping(array $overrides = []): SyncMapping
{
    $connection = Connection::create(['name' => 'Registration', 'driver' => 'suite', 'base_url' => 'https://reg.test', 'token' => 't']);

    return $connection->mappings()->create([
        'remote_event_id' => '5',
        'remote_event_name' => 'Spring Gala',
        'local_event_id' => 42,
        'target_table' => 'guests',
        'target_key_column' => 'external_id',
        'target_event_column' => 'event_id',
        'field_map' => Tables::consumerFieldMap(),
        ...$overrides,
    ]);
}

/**
 * @param  list<array<string, mixed>>  $rows
 */
function remoteSends(array $rows): void
{
    // One fake for the whole test, reading the latest rows: a second
    // Http::fake() for the same URL wouldn't replace the first.
    static $registered = null;
    $GLOBALS['connectRemoteRows'] = $rows;

    if ($registered !== test()) {
        $registered = test();
        Http::fake(['reg.test/api/v1/events/5/attendees*' => fn () => Http::response(['data' => $GLOBALS['connectRemoteRows'], 'links' => ['next' => null]])]);
    }
}

function ada(array $overrides = []): array
{
    return ['id' => 'a1', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com', 'table' => 'T3', 'extra' => ['dietary' => 'Vegan'], ...$overrides];
}

it('creates, updates only what changed, and skips unchanged rows', function () {
    $mapping = guestMapping();

    remoteSends([ada()]);
    SyncMappingJob::dispatchSync($mapping);

    expect(DB::table('guests')->sole())->toMatchObject([
        'external_id' => 'a1', 'event_id' => 42, 'first_name' => 'Ada', 'surname' => 'Lovelace',
        'email' => 'ada@example.com', 'table_no' => 'T3', 'dietary' => 'Vegan',
    ]);

    remoteSends([ada(['table' => 'T9'])]);
    SyncMappingJob::dispatchSync($mapping);

    remoteSends([ada(['table' => 'T9'])]);
    SyncMappingJob::dispatchSync($mapping);

    $runs = $mapping->runs()->oldest('id')->get();

    expect(DB::table('guests')->value('table_no'))->toBe('T9')
        ->and($runs->map->only('created', 'updated', 'skipped')->all())->toBe([
            ['created' => 1, 'updated' => 0, 'skipped' => 0],
            ['created' => 0, 'updated' => 1, 'skipped' => 0],
            ['created' => 0, 'updated' => 0, 'skipped' => 1],
        ]);
});

it('leaves a local column alone when the source stops sending that field', function () {
    $mapping = guestMapping();
    remoteSends([ada()]);
    SyncMappingJob::dispatchSync($mapping);

    remoteSends([['id' => 'a1', 'first_name' => 'Ada']]);
    SyncMappingJob::dispatchSync($mapping);

    expect(DB::table('guests')->sole())->toMatchObject(['email' => 'ada@example.com', 'table_no' => 'T3', 'dietary' => 'Vegan']);
});

it('deletes, soft-deletes or keeps rows the source deleted, as the mapping says', function (string $onDelete, ?bool $rowRemains, ?bool $softDeleted) {
    $mapping = guestMapping(['on_delete' => $onDelete]);
    remoteSends([ada()]);
    SyncMappingJob::dispatchSync($mapping);

    remoteSends([['id' => 'a1', 'deleted_at' => '2026-03-02T09:00:00Z']]);
    SyncMappingJob::dispatchSync($mapping);

    $row = DB::table('guests')->first();

    expect($row !== null)->toBe($rowRemains)
        ->and($row === null ? null : $row->deleted_at !== null)->toBe($softDeleted);
})->with([
    'delete' => ['delete', false, null],
    'soft delete' => ['soft_delete', true, true],
    'ignore' => ['ignore', true, false],
]);

it('holds rows it cannot write, with the payload and a reason, then resolves them once fixed', function () {
    $mapping = guestMapping(['field_map' => [...Tables::consumerFieldMap(), ['from' => 'company', 'to' => 'organisation']]]);
    remoteSends([ada(), ada(['id' => 'a2', 'first_name' => ''])]);

    SyncMappingJob::dispatchSync($mapping);
    SyncMappingJob::dispatchSync($mapping);

    $issues = SyncIssue::orderBy('remote_id')->get();

    expect($mapping->runs()->latest('id')->first()->status)->toBe(SyncRunStatus::Partial)
        ->and($issues->pluck('remote_id')->all())->toBe(['a1', 'a2'])
        ->and($issues[0]->reason)->toContain("The field map writes 'organisation', which isn't a column on 'guests'.")
        ->and($issues[0]->occurrences)->toBe(2)
        ->and($issues[0]->payload)->toEqual(ada())
        ->and($issues[1]->reason)->toBe('Missing required first_name.')
        ->and($mapping->fresh()->health())->toBe(SyncHealth::Failing);

    // Fix the mapping in the UI, then retry: no resync needed.
    $mapping->update(['field_map' => [...Tables::consumerFieldMap(), ['from' => 'company', 'to' => 'company']]]);

    $result = app(RetryHeldRows::class)->retry($mapping);

    expect($result)->toBe(['fixed' => 1, 'failed' => 1])
        ->and(DB::table('guests')->where('external_id', 'a1')->exists())->toBeTrue()
        ->and(SyncIssue::open()->pluck('remote_id')->all())->toBe(['a2']);
});

it('resolves a held row when the source later sends it fixed', function () {
    $mapping = guestMapping();
    remoteSends([ada(['first_name' => ''])]);
    SyncMappingJob::dispatchSync($mapping);

    remoteSends([ada()]);
    SyncMappingJob::dispatchSync($mapping);

    expect(SyncIssue::sole()->resolved_at)->not->toBeNull()
        ->and($mapping->fresh()->health())->toBe(SyncHealth::Healthy);
});

it('holds every row with a clear reason when the destination is not set up', function () {
    $mapping = guestMapping(['target_table' => null]);
    remoteSends([ada()]);

    SyncMappingJob::dispatchSync($mapping);

    expect(SyncIssue::sole()->reason)->toBe('No destination table is set. Edit the event mapping and choose one.');
});

it('records field coverage on each run', function () {
    $mapping = guestMapping();
    remoteSends([ada(), ada(['id' => 'a2', 'email' => null]), ['id' => 'a3', 'deleted_at' => '2026-03-02T09:00:00Z']]);

    SyncMappingJob::dispatchSync($mapping);

    $run = $mapping->runs()->sole();

    expect($run->row_count)->toBe(2)
        ->and($run->coverage['fields'])->toEqual([
            'email' => 1, 'extra.dietary' => 2, 'first_name' => 2, 'last_name' => 2, 'table' => 2,
        ])
        ->and($run->warnings)->toBe([])
        ->and($mapping->fresh()->seen_fields)->toEqualCanonicalizing(['first_name', 'last_name', 'email', 'table', 'extra.dietary']);
});

it('warns and alerts when a mapped field stops arriving or a new one appears, but only once', function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    config()->set('connect.consumer.alert_recipients', StubAlertRecipients::class);
    Notification::fake();
    $admin = User::create(['name' => 'Admin One']);

    $mapping = guestMapping();
    remoteSends([ada()]);
    SyncMappingJob::dispatchSync($mapping);

    Notification::assertNothingSent();

    // The source renamed its table field and added a phone number.
    remoteSends([ada(['table' => null, 'extra' => ['dietary' => 'Vegan', 'phone' => '0123']])]);
    SyncMappingJob::dispatchSync($mapping);
    SyncMappingJob::dispatchSync($mapping);

    $warned = $mapping->runs()->oldest('id')->get()[1];

    expect($warned->status)->toBe(SyncRunStatus::Succeeded)
        ->and($warned->warnings)->toBe([
            "'table' (mapped to 'table_no') was empty in all 1 row.",
            "The source started sending 'extra.phone', which isn't mapped to anything.",
        ])
        ->and($mapping->fresh()->load('latestRun')->health())->toBe(SyncHealth::Attention);

    Notification::assertSentToTimes($admin, DatabaseNotification::class, 1);
    Notification::assertSentTo($admin, DatabaseNotification::class, fn (DatabaseNotification $n) => $n->data['title'] === 'Synced Spring Gala: check the field mapping');
});
