<?php

namespace Jma\Connect\Consumer\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Jma\Connect\Data\AttendeeData;

/**
 * A held row: an attendee the sync couldn't write, kept with exactly what
 * the source sent so it can be retried once the mapping is fixed, without
 * a full resync. One per (mapping, remote attendee); a repeat failure bumps
 * occurrences, and the next successful write of that attendee resolves it.
 */
class SyncIssue extends Model
{
    protected $table = 'connect_sync_issues';

    protected $fillable = ['sync_mapping_id', 'sync_run_id', 'remote_id', 'reason', 'payload', 'occurrences', 'last_seen_at', 'resolved_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'last_seen_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(SyncMapping::class, 'sync_mapping_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'sync_run_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    public function attendee(): AttendeeData
    {
        return AttendeeData::fromArray($this->payload);
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }
}
