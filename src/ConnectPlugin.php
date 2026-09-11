<?php

namespace Jma\Connect;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Jma\Connect\Consumer\Filament\Resources\Connections\ConnectionResource;
use Jma\Connect\Provider\Filament\Resources\ApiClients\ApiClientResource;

class ConnectPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'connect';
    }

    public function register(Panel $panel): void
    {
        if (config('connect.provider.enabled')) {
            $panel->resources([ApiClientResource::class]);
        }

        if (config('connect.consumer.enabled')) {
            $panel->resources([ConnectionResource::class]);
        }
    }

    public function boot(Panel $panel): void {}
}
