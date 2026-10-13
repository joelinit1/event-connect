<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Jma\Connect\Consumer\Models\Connection;
use Jma\Connect\Tests\Fixtures\Models\Client;
use Jma\Connect\Tests\Fixtures\TenantUser;
use Jma\Connect\Tests\SharedTenantPanelTestCase;

uses(SharedTenantPanelTestCase::class);

it('works inside a panel shared with clients through tenancy', function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->timestamps();
    });
    Schema::create('clients', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $client = Client::forceCreate(['name' => 'Acme']);
    $connection = Connection::create(['name' => 'Registration', 'driver' => 'suite', 'base_url' => 'https://reg.test', 'token' => 't']);

    $this->actingAs(TenantUser::create(['name' => 'Admin', 'email' => 'admin@example.com']));

    $this->get("/admin/{$client->id}/connect-attendee-sources")->assertOk();
    $this->get("/admin/{$client->id}/connect-attendee-sources/create")->assertOk();
    $this->get("/admin/{$client->id}/connect-api-clients")->assertOk();
    $this->get("/admin/{$client->id}/connect-connections")->assertOk()->assertSee('Registration');
    $this->get("/admin/{$client->id}/connect-connections/{$connection->id}/edit")->assertOk();

    // Someone not on the admin list sees nothing, even inside a client.
    $this->actingAs(TenantUser::create(['name' => 'Client user', 'email' => 'client@example.com']));
    $this->get("/admin/{$client->id}/connect-connections")->assertForbidden();
});
