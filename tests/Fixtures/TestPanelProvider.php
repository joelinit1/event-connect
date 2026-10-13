<?php

namespace Jma\Connect\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;
use Jma\Connect\ConnectPlugin;

/**
 * A host panel with the plugin registered: the one line of code a host app
 * adds when installing the package.
 */
class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->default()
            ->plugin(ConnectPlugin::make());
    }
}
