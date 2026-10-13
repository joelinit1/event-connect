<?php

namespace Jma\Connect\Tests\Fixtures;

use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Jma\Connect\Tests\Fixtures\Models\Client;

/** A super admin in an app whose one panel is shared with clients. */
class TenantUser extends User implements HasTenants
{
    protected $table = 'users';

    public function getTenants(Panel $panel): Collection
    {
        return Client::all();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return true;
    }
}
