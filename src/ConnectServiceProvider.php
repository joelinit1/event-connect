<?php

namespace Jma\Connect;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Jma\Connect\Consumer\Console\SyncCommand;
use Jma\Connect\Contracts\AttendeeProvider;
use Jma\Connect\Contracts\AttendeeSink;
use RuntimeException;

class ConnectServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/connect.php', 'connect');

        $this->app->bind(AttendeeProvider::class, function (Application $app): AttendeeProvider {
            $class = config('connect.provider.class')
                ?? throw new RuntimeException('connect.provider.class is not configured.');

            return $app->make($class);
        });

        $this->app->bind(AttendeeSink::class, function (Application $app): AttendeeSink {
            $class = config('connect.consumer.sink')
                ?? throw new RuntimeException('connect.consumer.sink is not configured.');

            return $app->make($class);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/connect.php' => config_path('connect.php'),
        ], 'connect-config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

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
