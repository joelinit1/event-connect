<?php

namespace Jma\Connect\Consumer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Jma\Connect\Enums\SyncRunStatus;

class SyncRun extends Model
{
    public const MAX_ERRORS = 50;

    protected $table = 'connect_sync_runs';

    protected $fillable = ['sync_mapping_id', 'status', 'started_at', 'finished_at', 'created', 'updated', 'deleted', 'skipped', 'errors'];

    protected function casts(): array
    {
        return [
            'status' => SyncRunStatus::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'errors' => 'array',
        ];
    }

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(SyncMapping::class, 'sync_mapping_id');
    }
}
