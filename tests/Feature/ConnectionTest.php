<?php

use Illuminate\Support\Facades\DB;
use Jma\Connect\Consumer\Models\Connection;
use Jma\Connect\Consumer\Sources\SuiteSource;

it('stores the token encrypted and hides it from serialisation', function () {
    $connection = Connection::create(['name' => 'LLS', 'base_url' => 'https://source.test', 'token' => 'plain-text-secret']);

    $raw = DB::table('connect_connections')->find($connection->id)->token;

    expect($raw)->not->toContain('plain-text-secret')
        ->and($connection->fresh()->token)->toBe('plain-text-secret')
        ->and($connection->toArray())->not->toHaveKey('token');
});

it('builds the driver registered for its driver name', function () {
    $connection = Connection::create(['name' => 'LLS', 'driver' => 'suite', 'base_url' => 'https://source.test', 'token' => 't']);

    expect($connection->source())->toBeInstanceOf(SuiteSource::class);
});

it('rejects an unknown driver', function () {
    Connection::create(['name' => 'X', 'driver' => 'nope', 'base_url' => 'https://source.test', 'token' => 't'])->source();
})->throws(InvalidArgumentException::class, 'nope');
