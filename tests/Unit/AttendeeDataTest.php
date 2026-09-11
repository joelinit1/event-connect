<?php

use Carbon\CarbonImmutable;
use Jma\Connect\Data\AttendeeData;
use Jma\Connect\Data\EventData;

it('round-trips an attendee through the wire format', function () {
    $original = new AttendeeData(
        id: '42',
        firstName: 'Ada',
        lastName: 'Lovelace',
        email: 'ada@example.com',
        company: 'Analytical Engines',
        jobTitle: 'Mathematician',
        table: 'T3',
        seat: '2',
        zone: 'Main hall',
        checkedInAt: CarbonImmutable::parse('2026-03-01T18:30:00Z'),
        updatedAt: CarbonImmutable::parse('2026-03-01T12:00:00Z'),
        extra: ['full_name' => 'Ada Lovelace'],
    );

    $restored = AttendeeData::fromArray($original->toArray());

    expect($restored->toArray())->toBe($original->toArray())
        ->and($restored->checkedInAt?->toIso8601String())->toBe('2026-03-01T18:30:00+00:00')
        ->and($restored->isDeleted())->toBeFalse();
});

it('treats a deleted_at value as a tombstone and coerces a numeric id to string', function () {
    $attendee = AttendeeData::fromArray(['id' => 7, 'deleted_at' => '2026-03-02T00:00:00Z']);

    expect($attendee->id)->toBe('7')
        ->and($attendee->isDeleted())->toBeTrue()
        ->and($attendee->firstName)->toBe('');
});

it('round-trips an event through the wire format', function () {
    $original = new EventData(id: '9', name: 'Spring Gala', slug: 'spring-gala', startsAt: CarbonImmutable::parse('2026-05-01'));

    expect(EventData::fromArray($original->toArray())->toArray())->toBe($original->toArray());
});
