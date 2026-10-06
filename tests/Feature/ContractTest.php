<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Jma\Connect\Consumer\Models\Connection;
use Jma\Connect\Data\AttendeeData;
use Jma\Connect\Data\EventData;

/*
 * The JSON files in tests/Fixtures/contract/v1 are the /api/v1 wire format,
 * written out by hand. Provider and consumer can run different package
 * versions, so a failure here means the format changed: adding a key is fine
 * (update the fixture), but renaming or removing one needs /api/v2. See the
 * Versioning section of the README.
 */

function contract(string $name): array
{
    return json_decode(file_get_contents(__DIR__."/../Fixtures/contract/v1/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
}

it('reads and writes every attendee in the contract unchanged', function () {
    foreach (contract('attendees')['data'] as $row) {
        expect(AttendeeData::fromArray($row)->toArray())->toBe($row);
    }
});

it('serialises an attendee to exactly the contract keys', function () {
    $attendee = new AttendeeData(
        id: 'a1',
        firstName: 'Ada',
        lastName: 'Lovelace',
        email: 'ada@example.com',
        company: 'Analytical Engines',
        jobTitle: 'Mathematician',
        table: 'T3',
        seat: '2',
        zone: 'Main hall',
        bookingRef: '7K3F9Q',
        category: 'VIP',
        checkInCode: 'Q4M8TZ',
        checkedInAt: CarbonImmutable::parse('2026-03-01T18:30:00Z'),
        updatedAt: CarbonImmutable::parse('2026-03-01T12:00:00Z'),
        extra: ['dietary' => 'Vegetarian'],
    );

    expect($attendee->toArray())->toBe(contract('attendees')['data'][0]);
});

it('reads and writes every event in the contract unchanged', function () {
    foreach (contract('events')['data'] as $row) {
        expect(EventData::fromArray($row)->toArray())->toBe($row);
    }
});

it('serialises an event to exactly the contract keys', function () {
    $event = new EventData(
        id: '9',
        name: 'Spring Gala',
        slug: 'spring-gala',
        startsAt: CarbonImmutable::parse('2026-05-01T18:00:00Z'),
        endsAt: CarbonImmutable::parse('2026-05-01T23:30:00Z'),
        updatedAt: CarbonImmutable::parse('2026-04-20T10:00:00Z'),
    );

    expect($event->toArray())->toBe(contract('events')['data'][0]);
});

it('consumes contract responses through the suite source', function () {
    Http::fake([
        'source.test/api/v1/events/9/attendees*' => Http::response(contract('attendees')),
        'source.test/api/v1/events*' => Http::response(contract('events')),
    ]);

    $source = Connection::create([
        'name' => 'Contract',
        'driver' => 'suite',
        'base_url' => 'https://source.test',
        'token' => 'secret-token',
    ])->source();

    expect($source->events()->map->toArray()->all())->toBe(contract('events')['data'])
        ->and($source->attendees('9', null)->map->toArray()->all())->toBe(contract('attendees')['data']);
});
