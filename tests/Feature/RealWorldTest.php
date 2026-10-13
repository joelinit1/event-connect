<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Jma\Connect\Consumer\Jobs\SyncMappingJob;
use Jma\Connect\Consumer\Models\Connection;
use Jma\Connect\Consumer\Models\SyncMapping;
use Jma\Connect\Consumer\Sync\MappingPreview;
use Jma\Connect\Consumer\Sync\RetryHeldRows;
use Jma\Connect\Mapping\Suggest;
use Jma\Connect\Provider\Models\ApiClient;
use Jma\Connect\Provider\Models\AttendeeSource;
use Jma\Connect\Support\LocalTables;
use Jma\Connect\Tests\Fixtures\Models\ScannerAttendee;
use Jma\Connect\Tests\Fixtures\RealWorld;

/*
 * A registration app feeding a check-in app, with tables shaped like real
 * ones, set up only with what the screens save and suggest. The check-in
 * table needs an event, generates its token in the model, and has no column
 * for the registration app's ID.
 */
beforeEach(function () {
    config()->set('connect.provider.class', null);
    config()->set('connect.consumer.sink', null);
    config()->set('connect.consumer.models', [ScannerAttendee::class]);
    config()->set('mail.default', 'array');

    RealWorld::registrationApp();
    RealWorld::checkInApp();

    Http::fake(function (Request $request) {
        $url = parse_url($request->url());
        $response = $this->withToken(substr($request->header('Authorization')[0] ?? '', 7))
            ->getJson($url['path'].(isset($url['query']) ? "?{$url['query']}" : ''));

        return Http::response($response->json(), $response->status());
    });
});

function sentMail(): int
{
    return count(app('mailer')->getSymfonyTransport()->messages());
}

function checkInMapping(array $overrides = []): SyncMapping
{
    $source = AttendeeSource::create([
        'name' => 'Summer Party',
        'table_name' => 'reg_attendees',
        'id_column' => 'id',
        'filter_column' => 'event_id',
        'filter_value' => '1',
        'updated_at_column' => 'updated_at',
        'deleted_at_column' => 'deleted_at',
        'field_map' => Suggest::forProvider(LocalTables::columns('reg_attendees'), (array) DB::table('reg_attendees')->first(), ['id', 'event_id', 'updated_at', 'deleted_at']),
    ]);

    $token = ApiClient::create(['name' => 'Check-in'])->issueToken(['events:read', 'attendees:read'], null);
    $connection = Connection::create(['name' => 'Registration', 'driver' => 'suite', 'base_url' => 'https://registration.test', 'token' => $token]);

    $mapping = new SyncMapping([
        'connection_id' => $connection->id,
        'remote_event_id' => (string) $source->id,
        'remote_event_name' => 'Summer Party',
        'target_table' => 'scanner_attendees',
        'target_key_column' => null,
        'target_event_column' => 'event_id',
        'local_event_id' => 7,
        ...$overrides,
    ]);
    $mapping->setRelation('connection', $connection);
    $mapping->field_map ??= Suggest::forConsumer(MappingPreview::remoteFields($mapping), LocalTables::columns('scanner_attendees'), ['event_id']);
    $mapping->save();

    return $mapping;
}

it('offers the events a column points at, labelled by name', function () {
    expect(LocalTables::valueOptions('reg_attendees', 'event_id'))->toBe(['1' => 'Summer Party (#1)', '2' => 'Winter Ball (#2)'])
        ->and(LocalTables::valueOptions('scanner_attendees', 'event_id'))->toBe(['7' => 'Summer Party (check-in) (#7)']);
});

it('suggests usable field maps on both sides', function () {
    RealWorld::registrant('Ada King Lovelace');
    $mapping = checkInMapping();

    $provider = collect(AttendeeSource::sole()->field_map)->map(fn ($r) => trim("{$r['from']} → {$r['to']} ".($r['transform'] ?? '')))->all();
    $consumer = collect($mapping->field_map)->map(fn ($r) => "{$r['from']} → {$r['to']}")->all();

    expect($provider)->toContain('check_in_code → check_in_code', 'email → email')
        ->and($consumer)->toContain('email → email', 'extra.phone → phone')
        ->and(collect($mapping->field_map)->firstWhere('to', 'name')['from'] ?? null)->toBe('{first_name} {last_name}');
});

it('syncs into a table whose model generates required values, with no remote ID column', function () {
    $ada = RealWorld::registrant('Ada King Lovelace');
    RealWorld::registrant('Grace Hopper');
    RealWorld::registrant('Someone Else', ['event_id' => 2]);

    $mapping = checkInMapping();

    // Preview really writes each row and undoes it, without sending mail.
    $preview = MappingPreview::preview($mapping);
    expect($preview['problems'])->toBe([])
        ->and(collect($preview['rows'])->pluck('→ result')->all())->toBe(['create', 'create'])
        ->and(DB::table('scanner_attendees')->count())->toBe(0)
        ->and(sentMail())->toBe(0);

    $run = SyncMappingJob::runNow($mapping);

    expect($run->status->value)->toBe('succeeded')
        ->and($run->created)->toBe(2)
        ->and(sentMail())->toBe(2);

    $row = ScannerAttendee::where('email', 'ada.king.lovelace@example.com')->sole();
    expect($row)->name->toBe('Ada King Lovelace')
        ->event_id->toBe(7)
        ->phone->toBe('07700 900123')
        ->token->toHaveLength(16);
    $token = $row->token;

    // An edit at the source updates the same row; the token is untouched.
    DB::table('reg_attendees')->where('id', $ada)->update(['full_name' => 'Ada Lovelace', 'updated_at' => now()->addMinute()]);
    $this->travel(2)->minutes();
    $run = SyncMappingJob::runNow($mapping);

    expect($run->updated)->toBe(1)
        ->and(ScannerAttendee::count())->toBe(2)
        ->and($row->fresh())->name->toBe('Ada Lovelace')->token->toBe($token);

    // A cancellation soft-deletes it through the model; re-registering restores it.
    DB::table('reg_attendees')->where('id', $ada)->update(['deleted_at' => now()->addMinutes(3)]);
    $this->travel(5)->minutes();
    expect(SyncMappingJob::runNow($mapping)->deleted)->toBe(1)
        ->and($row->fresh()->trashed())->toBeTrue();

    DB::table('reg_attendees')->where('id', $ada)->update(['deleted_at' => null, 'updated_at' => now()->addMinute()]);
    $this->travel(2)->minutes();
    expect(SyncMappingJob::runNow($mapping)->updated)->toBe(1)
        ->and($row->fresh()->trashed())->toBeFalse()
        ->and(ScannerAttendee::withTrashed()->count())->toBe(2);
});

it('explains a required column nothing fills, in preview and on held rows', function () {
    RealWorld::registrant('Grace Hopper');
    $mapping = checkInMapping(['target_event_column' => null, 'local_event_id' => null]);

    $result = collect(MappingPreview::preview($mapping)['rows'])->pluck('→ result')->sole();
    expect($result)->toStartWith("held: Column 'event_id' needs a value");

    $run = SyncMappingJob::runNow($mapping);
    expect($run->status->value)->toBe('partial')
        ->and($mapping->openIssues()->sole()->reason)->toStartWith("Column 'event_id' needs a value");

    // Fix the mapping on the screen, then retry the held row: no resync.
    $mapping->update(['target_event_column' => 'event_id', 'local_event_id' => 7]);
    expect(app(RetryHeldRows::class)->retry($mapping))->toBe(['fixed' => 1, 'failed' => 0])
        ->and(ScannerAttendee::sole()->name)->toBe('Grace Hopper');
});

it('removes people the source no longer has, but never rows added by hand', function () {
    $ada = RealWorld::registrant('Ada Lovelace');
    $grace = RealWorld::registrant('Grace Hopper');
    RealWorld::registrant('Mary Somerville');
    $mapping = checkInMapping();

    expect(SyncMappingJob::runNow($mapping)->created)->toBe(3);

    $walkUp = ScannerAttendee::forceCreate(['event_id' => 7, 'name' => 'Walk-up', 'source' => 'ad_hoc']);

    // Ada moves to another event (stops matching the source's filter);
    // Grace's row is erased outright. Neither is ever sent as a deletion.
    DB::table('reg_attendees')->where('id', $ada)->update(['event_id' => 2]);
    DB::table('reg_attendees')->where('id', $grace)->delete();

    $run = SyncMappingJob::runNow($mapping);

    expect($run->deleted)->toBe(2)
        ->and($run->warnings)->toBe([])
        ->and(ScannerAttendee::pluck('name')->sort()->values()->all())->toBe(['Mary Somerville', 'Walk-up'])
        ->and($walkUp->fresh()->trashed())->toBeFalse();

    // Moving back restores the same row rather than creating another.
    DB::table('reg_attendees')->where('id', $ada)->update(['event_id' => 1]);

    expect(SyncMappingJob::runNow($mapping)->updated)->toBe(1)
        ->and(ScannerAttendee::withTrashed()->where('name', 'Ada Lovelace')->count())->toBe(1);
});

it('refuses to remove most of the list unless forced, and says why', function () {
    foreach (range(1, 8) as $i) {
        RealWorld::registrant("Person {$i}");
    }
    $mapping = checkInMapping();
    SyncMappingJob::runNow($mapping);

    // The source is pointed at the wrong event by mistake: it sends nobody.
    AttendeeSource::sole()->update(['filter_value' => '2']);

    $run = SyncMappingJob::runNow($mapping);

    expect($run->deleted)->toBe(0)
        ->and(ScannerAttendee::count())->toBe(8)
        ->and($run->warnings)->toHaveCount(1)
        ->and($run->warnings[0])->toContain('none were removed')
        ->and($mapping->fresh()->health()->value)->toBe('attention');

    // Full resync is the deliberate override.
    expect(SyncMappingJob::runNow($mapping, force: true)->deleted)->toBe(8)
        ->and(ScannerAttendee::count())->toBe(0);
});
