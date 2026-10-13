<?php

namespace Jma\Connect;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Jma\Connect\Consumer\Console\SyncCommand;
use Jma\Connect\Consumer\Sinks\TableAttendeeSink;
use Jma\Connect\Contracts\AttendeeProvider;
use Jma\Connect\Contracts\AttendeeSink;
use Jma\Connect\Provider\TableAttendeeProvider;

class ConnectServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/connect.php', 'connect');

        // Unset means the built-in, table-driven implementations, configured
        // from Filament rather than in code.
        $this->app->bind(AttendeeProvider::class, fn (Application $app): AttendeeProvider => $app->make(config('connect.provider.class') ?? TableAttendeeProvider::class));

        $this->app->bind(AttendeeSink::class, fn (Application $app): AttendeeSink => $app->make(config('connect.consumer.sink') ?? TableAttendeeSink::class));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/connect.php' => config_path('connect.php'),
        ], 'connect-config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'connect');

        // After every provider has booted, so a host that defines the ability
        // itself always wins over the email-list fallback.
        $this->app->booted(function (): void {
            if (! Gate::has(Connect::GATE)) {
                Gate::define(Connect::GATE, fn ($user) => Connect::isAdminEmail($user?->email ?? null));
            }
        });

        if (config('connect.consumer.enabled') && config('connect.consumer.schedule')) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('connect:sync')->cron(config('connect.consumer.schedule'))->withoutOverlapping();
            });
        }

        // Own limiter rather than the host's `api` one, which only exists
        // when the host registered routes/api.php.
        RateLimiter::for('connect', fn (Request $request) => Limit::perMinute(120)->by($request->bearerToken() ?? $request->ip()));

        if (config('connect.provider.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/Provider/routes.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([SyncCommand::class]);
        }
    }
}
