<?php

use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Jma\Connect\Tests\Fixtures\User;
use Jma\Connect\Tests\InstallTestCase;

uses(InstallTestCase::class);

it('adds its screens to the default panel without the host registering the plugin', function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->timestamps();
    });

    expect(Filament::getPanel('admin')->hasPlugin('connect'))->toBeTrue();

    $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com']));

    $this->get('/admin/connect-attendee-sources')->assertOk();
    $this->get('/admin/connect-attendee-sources/create')->assertOk();
    $this->get('/admin/connect-api-clients')->assertOk();
    $this->get('/admin/connect-connections')->assertOk();
    $this->get('/admin/connect-connections/create')->assertOk();
});
