<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Jma\Connect\Consumer\Models\Connection;
use Jma\Connect\Data\AttendeeData;

function connection(): Connection
{
    return Connection::create([
        'name' => 'LLS',
        'driver' => 'suite',
        'base_url' => 'https://source.test/',
        'token' => 'secret-token',
    ]);
}

it('follows pagination links and yields attendee DTOs', function () {
    Http::fake([
        'source.test/api/v1/events/7/attendees*' => Http::sequence()
            ->push([
                'data' => [['id' => 1, 'first_name' => 'Ada', 'last_name' => 'Lovelace'], ['id' => 2, 'first_name' => 'Grace', 'last_name' => 'Hopper']],
                'links' => ['next' => 'https://source.test/api/v1/events/7/attendees?page=2&per_page=200'],
            ])
            ->push([
                'data' => [['id' => 3, 'first_name' => 'Mary', 'last_name' => 'Somerville']],
                'links' => ['next' => null],
            ]),
    ]);

    $attendees = connection()->source()
        ->attendees('7', CarbonImmutable::parse('2026-01-01T00:00:00Z'))
        ->all();

    expect($attendees)->toHaveCount(3)
        ->and($attendees[0])->toBeInstanceOf(AttendeeData::class)
        ->and(array_map(fn (AttendeeData $a) => $a->id, $attendees))->toBe(['1', '2', '3']);

    Http::assertSentCount(2);

    // The second request must be the next link as given, page number included.
    Http::assertSent(fn ($request) => $request->url() === 'https://source.test/api/v1/events/7/attendees?page=2&per_page=200');
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret-token')
        && str_contains($request->url(), 'updated_since=2026-01-01T00%3A00%3A00%2B00%3A00')
        && str_contains($request->url(), 'per_page=200'));
});

it('lists remote events', function () {
    Http::fake([
        'source.test/api/v1/events*' => Http::response([
            'data' => [['id' => 7, 'name' => 'Spring Gala']],
            'links' => ['next' => null],
        ]),
    ]);

    $events = connection()->source()->events();

    expect($events)->toHaveCount(1)
        ->and($events->first()->name)->toBe('Spring Gala');
});

it('surfaces an auth failure from test()', function () {
    Http::fake(['source.test/*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    connection()->source()->test();
})->throws(RequestException::class);

it('says plainly when Cloudflare blocks the request with a bot challenge', function () {
    Http::fake(['source.test/*' => Http::response('<html>Just a moment...</html>', 403, ['cf-mitigated' => 'challenge', 'Server' => 'cloudflare'])]);

    expect(fn () => connection()->source()->test())
        ->toThrow(RuntimeException::class, 'Cloudflare blocked this request to source.test with a bot challenge');
});
