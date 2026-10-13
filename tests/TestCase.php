<?php

namespace Jma\Connect\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Jma\Connect\ConnectServiceProvider;
use Jma\Connect\Tests\Fixtures\RecordingSink;
use Jma\Connect\Tests\Fixtures\StubAttendeeProvider;
use Laravel\Sanctum\SanctumServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    // Not RefreshDatabase: tests create tables, which on MySQL silently
    // commits a wrapping transaction, and then the code under test (the
    // preview's rolled-back trial write) wouldn't run as it does for real.
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        StubAttendeeProvider::reset();
        RecordingSink::reset();
    }

    protected function getPackageProviders($app): array
    {
        // Same order as a real app's package discovery (filament/* before
        // livewire/livewire): Filament rebinds Livewire's DataStore while
        // registering, so Livewire has to register after it.
        return [
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            SupportServiceProvider::class,
            SchemasServiceProvider::class,
            FormsServiceProvider::class,
            TablesServiceProvider::class,
            InfolistsServiceProvider::class,
            WidgetsServiceProvider::class,
            ActionsServiceProvider::class,
            NotificationsServiceProvider::class,
            FilamentServiceProvider::class,
            LivewireServiceProvider::class,
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
}
