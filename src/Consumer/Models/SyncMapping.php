<?php

namespace Jma\Connect\Consumer\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Jma\Connect\Enums\SyncHealth;
use Jma\Connect\Enums\SyncRunStatus;

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

    /**
     * Partial runs count: their row errors are recorded, but the sync itself
     * ran and advanced the cursor.
     */
    public function latestSuccessfulRun(): HasOne
    {
        return $this->hasOne(SyncRun::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->whereIn('status', [SyncRunStatus::Succeeded, SyncRunStatus::Partial]),
        );
    }

    /**
     * Not the cursor: a full resync clears that, which would make a healthy
     * mapping look overdue until the new run lands.
     */
    public function lastSyncedAt(): ?CarbonImmutable
    {
        return $this->latestSuccessfulRun?->finished_at;
    }

    /**
     * Eager-load latestRun and latestSuccessfulRun when calling this in a list.
     */
    public function health(): SyncHealth
    {
        if (! $this->enabled) {
            return SyncHealth::Off;
        }

        if (in_array($this->latestRun?->status, [SyncRunStatus::Failed, SyncRunStatus::Partial], true)) {
            return SyncHealth::Failing;
        }

        $staleAfter = (int) config('connect.consumer.stale_after_minutes', 120);

        if ($this->lastSyncedAt() === null || $this->lastSyncedAt()->lt(now()->subMinutes($staleAfter))) {
            return SyncHealth::Overdue;
        }

        return SyncHealth::Healthy;
    }
}
