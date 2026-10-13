<?php

namespace Jma\Connect\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;

/**
 * A host's panels as they were before installing the package: nothing
 * registered, so the package has to add its screens by itself.
 */
class PlainPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('admin')->path('admin')->default();
    }
}
