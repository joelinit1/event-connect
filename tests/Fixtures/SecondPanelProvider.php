<?php

namespace Jma\Connect\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;
use Jma\Connect\ConnectPlugin;

/**
 * A host that already registers the plugin itself, on a panel that isn't
 * the default, as 0.1.x hosts do.
 */
class SecondPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('platform')->path('platform')->plugin(ConnectPlugin::make());
    }
}
