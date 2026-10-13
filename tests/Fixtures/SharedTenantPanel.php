<?php

namespace Jma\Connect\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;
use Jma\Connect\Tests\Fixtures\Models\Client;

/** One panel for super admins and clients alike, with client tenancy. */
class SharedTenantPanel extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('admin')->path('admin')->default()->tenant(Client::class);
    }
}
