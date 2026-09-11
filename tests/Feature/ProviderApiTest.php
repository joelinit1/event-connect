<?php

use Jma\Connect\Provider\Models\ApiClient;
use Jma\Connect\Tests\Fixtures\StubAttendeeProvider;

function issueToken(array $abilities): string
{
    return ApiClient::create(['name' => 'Seating Plan'])->createToken('test', $abilities)->plainTextToken;
}

it('rejects requests without a token', function () {
    $this->getJson('/api/v1/events')->assertUnauthorized();
});

it('lists events in the standard paginated envelope', function () {
    $response = $this->withToken(issueToken(['events:read']))
        ->getJson('/api/v1/events?per_page=2');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.id', '1')
        ->assertJsonPath('data.0.name', 'Spring Gala')
        ->assertJsonPath('data.0.updated_at', '2026-01-01T10:00:00+00:00');

    expect($response->json('links.next'))->toContain('page=2')->toContain('per_page=2');
});

it('refuses a token that lacks the ability for the endpoint', function () {
    $this->withToken(issueToken(['events:read']))
        ->getJson('/api/v1/events/1/attendees')
        ->assertForbidden();
});

it('passes updated_since through to the provider and includes tombstones', function () {
    $response = $this->withToken(issueToken(['attendees:read']))
        ->getJson('/api/v1/events/1/attendees?updated_since=2026-01-15T00:00:00Z');

    $response->assertOk()
        ->assertJsonPath('data.0.id', 'a1')
        ->assertJsonPath('data.0.table', 'T1')
        ->assertJsonPath('data.0.deleted_at', null)
        ->assertJsonPath('data.1.deleted_at', '2026-02-01T09:00:00+00:00');

    expect(StubAttendeeProvider::$lastCall['eventId'])->toBe('1')
        ->and(StubAttendeeProvider::$lastCall['updatedSince']?->toIso8601String())->toBe('2026-01-15T00:00:00+00:00')
        ->and(StubAttendeeProvider::$lastCall['client'])->toBeInstanceOf(ApiClient::class);
});

it('validates listing parameters', function () {
    $this->withToken(issueToken(['events:read']))
        ->getJson('/api/v1/events?per_page=5000&updated_since=not-a-date')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['per_page', 'updated_since']);
});
