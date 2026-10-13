<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Jma\Connect\Connect;
use Jma\Connect\Consumer\Jobs\SyncMappingJob;
use Jma\Connect\Consumer\Models\Connection;
use Jma\Connect\Consumer\Models\SyncMapping;
use Jma\Connect\Consumer\Sync\MappingPreview;
use Jma\Connect\Mapping\Suggest;
use Jma\Connect\Provider\Models\ApiClient;
use Jma\Connect\Provider\Models\AttendeeSource;
use Jma\Connect\Support\LocalTables;
use Jma\Connect\Tests\Fixtures\Tables;

/*
 * Both halves in one app: the provider's API is served by this test app,
 * and the consumer's HTTP calls are routed back into it. Everything is set
 * up the way an admin would through the screens (records and suggestions),
 * with no AttendeeProvider or AttendeeSink classes written.
 */
beforeEach(function () {
    config()->set('connect.provider.class', null);
    config()->set('connect.consumer.sink', null);

    Tables::providerGuests();
    Tables::consumerGuests();

    Http::fake(function (Request $request) {
        $url = parse_url($request->url());
        $response = $this->withToken($request->header('Authorization')[0] ? substr($request->header('Authorization')[0], 7) : '')
            ->getJson($url['path'].(isset($url['query']) ? "?{$url['query']}" : ''));

        return Http::response($response->json(), $response->status());
    });
});

it('syncs an event between two different table layouts using only screen settings', function () {
    Tables::guest('G1', 'Ada King Lovelace', ['rsvp' => json_encode(['dietary' => 'Vegan', 'phone' => '0123'])]);
    Tables::guest('G2', 'Grace Hopper', ['table_id' => 7]);
    Tables::guest('X1', 'Other Event', ['event_code' => 'OTHER']);

    // Provider admin: Attendee Sources → New, pick the table, Suggest from table.
    $columns = LocalTables::columns('gala_guests');
    $source = AttendeeSource::create([
        'name' => 'Spring Gala',
        'table_name' => 'gala_guests',
        'id_column' => 'guest_ref',
        'filter_column' => 'event_code',
        'filter_value' => 'GALA27',
        'updated_at_column' => 'updated_at',
        'deleted_at_column' => 'deleted_at',
        'field_map' => Suggest::forProvider($columns, (array) DB::table('gala_guests')->first(), ['guest_ref', 'event_code', 'updated_at', 'deleted_at']),
    ]);

    // Provider admin: API Clients → New, copy the token.
    $token = ApiClient::create(['name' => 'Seating'])->issueToken(['events:read', 'attendees:read'], null);

    // Consumer admin: API Connections → New, paste URL and token.
    $connection = Connection::create(['name' => 'Registration', 'driver' => 'suite', 'base_url' => 'https://registration.test', 'token' => $token]);
    expect($connection->source()->events()->pluck('name')->all())->toBe(['Spring Gala']);

    // Consumer admin: Event mappings → New, pick event and table, Suggest from source.
    $mapping = new SyncMapping([
        'connection_id' => $connection->id,
        'remote_event_id' => (string) $source->id,
        'remote_event_name' => 'Spring Gala',
        'local_event_id' => 42,
        'target_table' => 'guests',
        'target_key_column' => 'external_id',
        'target_event_column' => 'event_id',
    ]);
    $mapping->setRelation('connection', $connection);
    $mapping->field_map = Suggest::forConsumer(MappingPreview::remoteFields($mapping), LocalTables::columns('guests'), ['external_id', 'event_id']);
    $mapping->save();

    expect(collect($mapping->field_map)->map(fn ($r) => "{$r['from']} → {$r['to']}")->all())->toEqualCanonicalizing([
        'first_name → first_name',
        'last_name → surname',
        'email → email',
        'company → company',
        'table → table_no',
        'extra.dietary → dietary',
    ]);

    // Preview before the first sync.
    $preview = MappingPreview::preview($mapping);

    expect($preview['problems'])->toBe([])
        ->and(collect($preview['rows'])->pluck('→ result')->all())->toBe(['create', 'create'])
        ->and($preview['notes'])->toBe(['Sent by the source but not mapped to any column: extra.phone, extra.plus_one.']);

    // Sync.
    SyncMappingJob::dispatchSync($mapping);

    expect(DB::table('guests')->orderBy('external_id')->get(['external_id', 'event_id', 'first_name', 'surname', 'email', 'company', 'table_no', 'dietary'])->map(fn ($r) => (array) $r)->all())->toBe([
        ['external_id' => 'G1', 'event_id' => 42, 'first_name' => 'Ada', 'surname' => 'King Lovelace', 'email' => 'ada@example.com', 'company' => 'Analytical Engines', 'table_no' => '3', 'dietary' => 'Vegan'],
        ['external_id' => 'G2', 'event_id' => 42, 'first_name' => 'Grace', 'surname' => 'Hopper', 'email' => 'grace@example.com', 'company' => 'Analytical Engines', 'table_no' => '7', 'dietary' => 'Vegan'],
    ]);

    // A change and a removal at the source arrive on the next sync.
    DB::table('gala_guests')->where('guest_ref', 'G2')->update(['table_id' => 9, 'updated_at' => now()->addMinute()]);
    DB::table('gala_guests')->where('guest_ref', 'G1')->update(['deleted_at' => now()->addMinute()]);
    $mapping->update(['cursor' => now()->subMinute()]);

    SyncMappingJob::dispatchSync($mapping);

    expect(DB::table('guests')->pluck('table_no', 'external_id')->all())->toBe(['G2' => '9'])
        ->and($mapping->runs()->latest('id')->first()->only('updated', 'deleted'))->toBe(['updated' => 1, 'deleted' => 1])
        ->and($mapping->fresh()->health()->value)->toBe('healthy');
});

it('lets listed admins in without a host-defined Gate', function () {
    config()->set('connect.admin_emails', 'ops@example.com, Joel@Example.com');

    expect(Gate::forUser((object) ['email' => 'joel@example.com'])->allows(Connect::GATE))->toBeTrue()
        ->and(Gate::forUser((object) ['email' => 'someone@example.com'])->allows(Connect::GATE))->toBeFalse();
});

it('schedules connect:sync itself', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'connect:sync'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *');
});
