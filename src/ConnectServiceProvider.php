<?php

namespace Jma\Connect;

use Filament\Panel;
use Filament\PanelRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        // Panels are built while the registry resolves; this runs after all
        // of them, so the screens appear on the super admin's panel without
        // the host registering the plugin. A host that registered it on a
        // panel itself is left alone.
        $this->app->afterResolving(PanelRegistry::class, function (PanelRegistry $registry): void {
            if (! config('connect.provider.enabled') && ! config('connect.consumer.enabled')) {
                return;
            }

            $panels = collect($registry->all());

            if ($panels->contains(fn (Panel $panel) => $panel->hasPlugin('connect'))) {
                return;
            }

            $panel = filled(config('connect.panel')) ? $panels->get(config('connect.panel')) : self::adminPanel($panels);

            $panel?->plugin(ConnectPlugin::make());
        });
    }

    /**
     * The panel an app's super admin uses: the default one, unless that's a
     * client panel (it has tenancy) and the app has a panel without tenancy,
     * which is then the cross-client admin (e.g. /platform beside /manage).
     *
     * @param  Collection<string, Panel>  $panels
     */
    public static function adminPanel(Collection $panels): ?Panel
    {
        $default = $panels->first(fn (Panel $panel) => $panel->isDefault()) ?? $panels->first();

        if ($default === null || ! $default->hasTenancy()) {
            return $default;
        }

        $untenanted = $panels->reject(fn (Panel $panel) => $panel->hasTenancy());

        return $untenanted->first(fn (Panel $panel) => in_array($panel->getId(), ['platform', 'admin', 'super-admin', 'superadmin'], true))
            ?? $untenanted->first()
            ?? $default;
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
                $schedule->command('connect:sync')->cron(config('connect.consumer.schedule'))->withoutOverlapping()->runInBackground();
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
