<?php

use Jma\Connect\Provider\Models\ApiClient;
use Jma\Connect\Provider\Models\AttendeeSource;
use Jma\Connect\Provider\SourcePreview;
use Jma\Connect\Tests\Fixtures\Tables;

beforeEach(function () {
    config()->set('connect.provider.class', null);
    Tables::providerGuests();
});

function gala(array $overrides = []): AttendeeSource
{
    return AttendeeSource::create([
        'name' => 'Spring Gala',
        'table_name' => 'gala_guests',
        'filter_column' => 'event_code',
        'filter_value' => 'GALA27',
        'id_column' => 'guest_ref',
        'updated_at_column' => 'updated_at',
        'deleted_at_column' => 'deleted_at',
        'field_map' => [
            ['from' => 'guest_name', 'to' => 'first_name', 'transform' => 'first_word'],
            ['from' => 'guest_name', 'to' => 'last_name', 'transform' => 'rest_words'],
            ['from' => 'email_address', 'to' => 'email'],
            ['from' => 'table_id', 'to' => 'table'],
            ['from' => 'rsvp.*', 'to' => 'extra'],
        ],
        ...$overrides,
    ]);
}

function providerToken(?ApiClient $client = null): string
{
    return ($client ?? ApiClient::create(['name' => 'Seating']))->createToken('t', ['events:read', 'attendees:read'])->plainTextToken;
}

it('lists configured sources as events', function () {
    $source = gala();
    gala(['name' => 'Hidden', 'enabled' => false]);

    $this->withToken(providerToken())->getJson('/api/v1/events')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $source->id)
        ->assertJsonPath('data.0.name', 'Spring Gala');
});

it('serves a table with its own column names in the standard wire format', function () {
    $source = gala();
    Tables::guest('G1', 'Ada King Lovelace');
    Tables::guest('G2', 'Cher', ['table_id' => null, 'rsvp' => null]);
    Tables::guest('X9', 'Other Event', ['event_code' => 'OTHER']);
    Tables::guest('G3', 'Gone Guest', ['deleted_at' => '2026-03-02 09:00:00']);

    $response = $this->withToken(providerToken())->getJson("/api/v1/events/{$source->id}/attendees")->assertOk();

    expect($response->json('data'))->toBe([
        [
            'id' => 'G1', 'first_name' => 'Ada', 'last_name' => 'King Lovelace', 'email' => 'ada@example.com',
            'table' => '3', 'updated_at' => '2026-03-01T12:00:00+00:00', 'extra' => ['dietary' => 'Vegan', 'plus_one' => true],
        ],
        [
            'id' => 'G2', 'first_name' => 'Cher', 'last_name' => '', 'email' => 'cher@example.com',
            'table' => null, 'updated_at' => '2026-03-01T12:00:00+00:00',
        ],
    ]);
});

it('sends changed rows and tombstones on an incremental read', function () {
    $source = gala();
    Tables::guest('G1', 'Old Row', ['updated_at' => '2026-01-01 00:00:00']);
    Tables::guest('G2', 'New Row', ['updated_at' => '2026-03-05 00:00:00']);
    Tables::guest('G3', 'Gone Guest', ['updated_at' => '2026-01-01 00:00:00', 'deleted_at' => '2026-03-06 00:00:00']);

    $response = $this->withToken(providerToken())
        ->getJson("/api/v1/events/{$source->id}/attendees?updated_since=2026-03-01T00:00:00Z")
        ->assertOk();

    expect($response->json('data.*.id'))->toBe(['G2', 'G3'])
        ->and($response->json('data.1'))->toBe(['id' => 'G3', 'updated_at' => '2026-01-01T00:00:00+00:00', 'deleted_at' => '2026-03-06T00:00:00+00:00']);
});

it('returns 404 for an unknown or disabled source, and hides other tenants\' sources', function () {
    config()->set('connect.provider.tenant_model', ApiClient::class);
    $mine = gala(['tenant_id' => 1]);
    $theirs = gala(['tenant_id' => 2]);
    $off = gala(['tenant_id' => 1, 'enabled' => false]);
    $token = providerToken(ApiClient::create(['name' => 'Seating', 'tenant_id' => 1]));

    $this->withToken($token)->getJson("/api/v1/events/{$mine->id}/attendees")->assertOk();
    $this->withToken($token)->getJson("/api/v1/events/{$theirs->id}/attendees")->assertNotFound();
    $this->withToken($token)->getJson("/api/v1/events/{$off->id}/attendees")->assertNotFound();
    $this->withToken($token)->getJson('/api/v1/events/nope/attendees')->assertNotFound();
});

it('reports configuration problems in plain words', function () {
    $source = gala(['id_column' => 'ref', 'field_map' => [['from' => 'mobile', 'to' => 'extra.phone']]]);

    expect($source->configurationProblems())->toBe([
        "Column 'ref' (id_column) isn't on table 'gala_guests'.",
        "Field map reads 'mobile', but table 'gala_guests' has no 'mobile' column.",
    ])->and(gala(['table_name' => 'nope'])->configurationProblems())->toBe(["Table 'nope' doesn't exist."]);
});

it('previews what a source sends and flags what needs attention', function () {
    Tables::guest('G1', 'Ada Lovelace');
    Tables::guest('G2', 'Grace Hopper');

    $good = SourcePreview::build(gala());
    $noName = SourcePreview::build(gala(['field_map' => [['from' => 'email_address', 'to' => 'email']]]));

    expect($good['problems'])->toBe([])
        ->and($good['rows'])->toHaveCount(2)
        ->and($good['rows'][0])->toMatchArray(['id' => 'G1', 'first_name' => 'Ada', 'extra.dietary' => 'Vegan'])
        ->and($good['notes'])->toBe(['Columns not sent: company_name. Map any the receiving app needs; leave the rest.'])
        ->and(collect($good['coverage'])->firstWhere('field', 'email'))->toBe(['field' => 'email', 'filled' => 2, 'total' => 2, 'target' => null])
        ->and($noName['problems'][0])->toContain('Nothing is mapped to first_name');
});
