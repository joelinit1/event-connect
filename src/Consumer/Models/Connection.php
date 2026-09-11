<?php

namespace Jma\Connect\Consumer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use InvalidArgumentException;
use Jma\Connect\Contracts\Source;

class Connection extends Model
{
    protected $table = 'connect_connections';

    protected $fillable = ['tenant_id', 'name', 'driver', 'base_url', 'token'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(config('connect.consumer.tenant_model'));
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(SyncMapping::class);
    }

    public function runs(): HasManyThrough
    {
        return $this->hasManyThrough(SyncRun::class, SyncMapping::class);
    }

    public function source(): Source
    {
        $class = config("connect.consumer.drivers.{$this->driver}")
            ?? throw new InvalidArgumentException("No connect driver registered as [{$this->driver}].");

        return app()->make($class, ['connection' => $this]);
    }
}
