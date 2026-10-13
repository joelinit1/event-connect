<?php

namespace Jma\Connect\Tests\Fixtures;

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelProvider;
use Jma\Connect\Tests\Fixtures\Models\Client;

/**
 * An app whose default panel is the clients' (with tenancy), beside a
 * separate super-admin panel without it, like seating-plan's /manage and
 * /platform.
 */
class ClientAndPlatformPanels extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('client')->path('manage')->default()->tenant(Client::class);
    }

    public function register(): void
    {
        parent::register();

        Filament::registerPanel(fn (): Panel => Panel::make()->id('platform')->path('platform'));
    }
}
