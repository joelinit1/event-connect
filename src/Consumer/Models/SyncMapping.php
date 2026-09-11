<?php

namespace Jma\Connect\Consumer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SyncMapping extends Model
{
    protected $table = 'connect_sync_mappings';

    protected $fillable = ['connection_id', 'remote_event_id', 'remote_event_name', 'local_event_id', 'enabled', 'cursor'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'cursor' => 'immutable_datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function localEvent(): BelongsTo
    {
        return $this->belongsTo(config('connect.consumer.event_model'), 'local_event_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(SyncRun::class)->latestOfMany();
    }
}
