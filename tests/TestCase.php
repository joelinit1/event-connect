<?php

namespace Jma\Connect\Tests;

use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Support\SupportServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Jma\Connect\ConnectServiceProvider;
use Jma\Connect\Tests\Fixtures\RecordingSink;
use Jma\Connect\Tests\Fixtures\StubAttendeeProvider;
use Laravel\Sanctum\SanctumServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        StubAttendeeProvider::reset();
        RecordingSink::reset();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            NotificationsServiceProvider::class,
            FilamentServiceProvider::class,
            SanctumServiceProvider::class,
            ConnectServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('connect.provider.enabled', true);
        $app['config']->set('connect.provider.class', StubAttendeeProvider::class);
        $app['config']->set('connect.consumer.enabled', true);
        $app['config']->set('connect.consumer.sink', RecordingSink::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../vendor/laravel/sanctum/database/migrations');
    }
}
