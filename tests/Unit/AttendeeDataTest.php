<?php

use Carbon\CarbonImmutable;
use Jma\Connect\Data\AttendeeData;
use Jma\Connect\Data\EventData;
use Jma\Connect\Exceptions\InvalidAttendeeData;

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
        bookingRef: '7K3F9Q',
        category: 'VIP',
        checkInCode: 'Q4M8TZ',
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

it('tells a key the source never sent apart from one it sent as null', function () {
    $attendee = AttendeeData::fromArray(['id' => 1, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => null]);

    expect($attendee->has('email'))->toBeTrue()
        ->and($attendee->has('company'))->toBeFalse()
        ->and($attendee->toArray())->toBe(['id' => '1', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => null]);
});

it('treats booking_ref as not sent by a provider that predates it', function () {
    $attendee = AttendeeData::fromArray(['id' => 1, 'first_name' => 'Ada', 'last_name' => 'Lovelace']);

    expect($attendee->has('booking_ref'))->toBeFalse()
        ->and($attendee->bookingRef)->toBeNull()
        ->and(AttendeeData::fromArray(['id' => 1, 'booking_ref' => '7K3F9Q'])->bookingRef)->toBe('7K3F9Q');
});

it('treats category as not sent by a provider that predates it', function () {
    $attendee = AttendeeData::fromArray(['id' => 1, 'first_name' => 'Ada', 'last_name' => 'Lovelace']);

    expect($attendee->has('category'))->toBeFalse()
        ->and($attendee->category)->toBeNull()
        ->and(AttendeeData::fromArray(['id' => 1, 'category' => 'VIP'])->category)->toBe('VIP');
});

it('treats check_in_code as not sent by a provider that predates it', function () {
    $attendee = AttendeeData::fromArray(['id' => 1, 'first_name' => 'Ada', 'last_name' => 'Lovelace']);

    expect($attendee->has('check_in_code'))->toBeFalse()
        ->and($attendee->checkInCode)->toBeNull()
        ->and(AttendeeData::fromArray(['id' => 1, 'check_in_code' => 'Q4M8TZ'])->checkInCode)->toBe('Q4M8TZ');
});

it('treats every field as sent when built in code', function () {
    $attendee = new AttendeeData(id: '1', firstName: 'Ada', lastName: 'Lovelace');

    expect($attendee->has('email'))->toBeTrue()
        ->and($attendee->toArray())->toHaveKeys(['email', 'company', 'extra']);
});

it('rejects a live attendee whose name is missing or blank', function (array $row, string $message) {
    expect(fn () => AttendeeData::fromArray(['id' => 1, ...$row])->assertValid())
        ->toThrow(InvalidAttendeeData::class, $message);
})->with([
    'first name absent' => [['last_name' => 'Lovelace'], 'Missing required first_name.'],
    'last name null' => [['first_name' => 'Ada', 'last_name' => null], 'Missing required last_name.'],
    'both blank' => [['first_name' => ' ', 'last_name' => ''], 'Missing required first_name, last_name.'],
]);

it('accepts a named attendee and a nameless tombstone', function () {
    AttendeeData::fromArray(['id' => 1, 'first_name' => 'Ada', 'last_name' => 'Lovelace'])->assertValid();
    AttendeeData::fromArray(['id' => 2, 'deleted_at' => '2026-03-02T00:00:00Z'])->assertValid();
})->throwsNoExceptions();
