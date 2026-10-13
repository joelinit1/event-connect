<?php

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Jma\Connect\Consumer\Filament\Resources\Connections\Pages\EditConnection;
use Jma\Connect\Consumer\Filament\Resources\Connections\Pages\ListConnections;
use Jma\Connect\Consumer\Filament\Resources\Connections\RelationManagers\SyncIssuesRelationManager;
use Jma\Connect\Consumer\Filament\Resources\Connections\RelationManagers\SyncMappingsRelationManager;
use Jma\Connect\Consumer\Models\Connection;
use Jma\Connect\Consumer\Models\SyncMapping;
use Jma\Connect\Provider\Filament\Resources\ApiClients\Pages\ListApiClients;
use Jma\Connect\Provider\Filament\Resources\AttendeeSources\Pages\CreateAttendeeSource;
use Jma\Connect\Provider\Filament\Resources\AttendeeSources\Pages\EditAttendeeSource;
use Jma\Connect\Provider\Filament\Resources\AttendeeSources\Pages\ListAttendeeSources;
use Jma\Connect\Provider\Models\ApiClient;
use Jma\Connect\Provider\Models\AttendeeSource;
use Jma\Connect\Tests\Fixtures\Tables;
use Jma\Connect\Tests\Fixtures\User;
use Livewire\Livewire;

beforeEach(function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->timestamps();
    });

    Filament::setCurrentPanel('admin');

    $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com']));

    Tables::providerGuests();
    Tables::consumerGuests();
    Tables::guest('G1', 'Ada Lovelace');
});

it('creates an attendee source from the screen, using Suggest', function () {
    Livewire::test(CreateAttendeeSource::class)
        ->fillForm(['name' => 'Spring Gala', 'table_name' => 'gala_guests'])
        ->assertSchemaStateSet(['id_column' => null, 'updated_at_column' => 'updated_at', 'deleted_at_column' => 'deleted_at'])
        ->fillForm(['id_column' => 'guest_ref'])
        ->callAction(TestAction::make('suggest')->schemaComponent('field_map'))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(collect(AttendeeSource::sole()->field_map)->pluck('to')->all())->toEqualCanonicalizing([
        'email', 'company', 'table', 'extra', 'first_name', 'last_name',
    ]);
});

it('lists, edits and previews attendee sources', function () {
    $source = AttendeeSource::create(['name' => 'Gala', 'table_name' => 'gala_guests', 'id_column' => 'guest_ref', 'field_map' => [['from' => 'guest_name', 'to' => 'first_name']]]);

    $list = Livewire::test(ListAttendeeSources::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$source])
        ->mountAction(TestAction::make('preview')->table($source))
        ->assertActionMounted(TestAction::make('preview')->table($source));

    // Filament's modal assertions don't see list-page modals, so render the
    // mounted action's content directly.
    expect($list->instance()->getMountedAction()->getModalContent()->render())
        ->toContain('First 1 row(s)')
        ->toContain('Columns not sent: event_code, email_address, company_name, table_id, rsvp.');

    Livewire::test(EditAttendeeSource::class, ['record' => $source->getRouteKey()])
        ->assertOk()
        ->assertActionVisible('preview')
        ->fillForm(['field_map' => [['from' => 'guest_name', 'to' => 'first_name'], ['from' => 'email_address', 'to' => 'email']]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($source->fresh()->field_map)->toHaveCount(2);
});

it('creates an event mapping from the screen, using Suggest, then previews it', function () {
    Http::fake([
        'reg.test/api/v1/events?*' => Http::response(['data' => [['id' => '5', 'name' => 'Spring Gala']], 'links' => ['next' => null]]),
        'reg.test/api/v1/events/5/attendees*' => Http::response(['data' => [
            ['id' => 'a1', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com', 'extra' => ['dietary' => 'Vegan']],
        ], 'links' => ['next' => null]]),
    ]);
    $connection = Connection::create(['name' => 'Registration', 'driver' => 'suite', 'base_url' => 'https://reg.test', 'token' => 't']);

    Livewire::test(SyncMappingsRelationManager::class, ['ownerRecord' => $connection, 'pageClass' => EditConnection::class])
        ->assertOk()
        ->mountAction(TestAction::make('create')->table())
        ->fillForm(['remote_event_id' => '5', 'target_table' => 'guests'])
        ->assertSchemaStateSet(['target_key_column' => 'external_id', 'target_event_column' => 'event_id'])
        ->fillForm(['local_event_id' => 42])
        ->callAction(TestAction::make('suggest')->schemaComponent('field_map'))
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $mapping = SyncMapping::sole();

    expect($mapping->remote_event_name)->toBe('Spring Gala')
        ->and(collect($mapping->field_map)->pluck('to', 'from')->all())->toBe([
            'first_name' => 'first_name', 'last_name' => 'surname', 'email' => 'email', 'extra.dietary' => 'dietary',
        ]);

    Livewire::test(SyncMappingsRelationManager::class, ['ownerRecord' => $connection, 'pageClass' => EditConnection::class])
        ->mountAction(TestAction::make('preview')->table($mapping))
        ->assertMountedActionModalSee(['First 1 row(s)', 'ada@example.com']);

    Livewire::test(SyncMappingsRelationManager::class, ['ownerRecord' => $connection, 'pageClass' => EditConnection::class])
        ->mountAction(TestAction::make('fieldReport')->table($mapping))
        ->assertMountedActionModalSee('No run has recorded field counts yet');
});

it('shows held rows and retries them', function () {
    $connection = Connection::create(['name' => 'Registration', 'driver' => 'suite', 'base_url' => 'https://reg.test', 'token' => 't']);
    $mapping = $connection->mappings()->create([
        'remote_event_id' => '5', 'remote_event_name' => 'Gala', 'local_event_id' => 1,
        'target_table' => 'guests', 'target_key_column' => 'external_id', 'field_map' => Tables::consumerFieldMap(),
    ]);
    $issue = $mapping->issues()->create(['remote_id' => 'a1', 'reason' => 'Was broken', 'payload' => ['id' => 'a1', 'first_name' => 'Ada'], 'last_seen_at' => now()]);

    Livewire::test(SyncIssuesRelationManager::class, ['ownerRecord' => $connection, 'pageClass' => EditConnection::class])
        ->assertOk()
        ->assertCanSeeTableRecords([$issue])
        ->assertSee('Was broken')
        ->callAction(TestAction::make('retry')->table($issue));

    expect($issue->fresh()->resolved_at)->not->toBeNull();
});

it('keeps the screens from users not on the admin list', function () {
    $this->actingAs(User::create(['name' => 'Client', 'email' => 'client@example.com']));

    Livewire::test(ListAttendeeSources::class)->assertForbidden();
});

it('renders the API clients and connections lists without a tenant model', function () {
    ApiClient::create(['name' => 'Seating']);
    Connection::create(['name' => 'Registration', 'driver' => 'suite', 'base_url' => 'https://reg.test', 'token' => 't']);

    Livewire::test(ListApiClients::class)->assertOk()->assertSee('Seating');
    Livewire::test(ListConnections::class)->assertOk()->assertSee('Registration');
    Livewire::test(EditConnection::class, ['record' => Connection::sole()->getRouteKey()])->assertOk();
});
