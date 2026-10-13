<?php

namespace Jma\Connect\Consumer\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Jma\Connect\Enums\SyncHealth;
use Jma\Connect\Enums\SyncRunStatus;
use Jma\Connect\Mapping\FieldMap;
use Jma\Connect\Support\LocalTables;

class SyncMapping extends Model
{
    protected $table = 'connect_sync_mappings';

    public const ON_DELETE = [
        'delete' => 'Delete the local row',
        'soft_delete' => 'Set its deleted_at column',
        'ignore' => 'Keep the local row',
    ];

    protected $fillable = [
        'connection_id', 'remote_event_id', 'remote_event_name', 'local_event_id', 'enabled', 'cursor',
        'target_table', 'target_key_column', 'target_event_column', 'on_delete', 'field_map', 'seen_fields',
    ];

    protected $attributes = [
        'on_delete' => 'delete',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'cursor' => 'immutable_datetime',
            'field_map' => 'array',
            'seen_fields' => 'array',
        ];
    }

    public function fieldMap(): FieldMap
    {
        return new FieldMap($this->field_map);
    }

    /**
     * Problems with the destination settings that would make every row fail
     * in the built-in TableAttendeeSink. Empty when it's ready to write.
     *
     * @return list<string>
     */
    public function destinationProblems(): array
    {
        if (blank($this->target_table)) {
            return ['No destination table is set. Edit the event mapping and choose one.'];
        }

        $columns = LocalTables::columns($this->target_table);

        if ($columns === []) {
            return ["Destination table '{$this->target_table}' doesn't exist."];
        }

        $problems = [];

        if (blank($this->target_key_column)) {
            $problems[] = 'No remote ID column is set, so synced rows can\'t be matched up again. Edit the event mapping and choose one.';
        }

        foreach (['target_key_column', 'target_event_column'] as $setting) {
            if (filled($this->{$setting}) && ! in_array($this->{$setting}, $columns, true)) {
                $problems[] = "Column '{$this->{$setting}}' isn't on table '{$this->target_table}'.";
            }
        }

        if (filled($this->target_event_column) && $this->local_event_id === null) {
            $problems[] = "An event column is set ('{$this->target_event_column}') but no local event is chosen.";
        }

        if ($this->on_delete === 'soft_delete' && ! in_array('deleted_at', $columns, true)) {
            $problems[] = "Deletes are set to soft-delete, but '{$this->target_table}' has no deleted_at column.";
        }

        if ($this->fieldMap()->isEmpty()) {
            $problems[] = 'The field map is empty, so nothing would be written. Use "Suggest from source" to start one.';
        }

        foreach ($this->fieldMap()->targets() as $column) {
            if (! in_array($column, $columns, true)) {
                $problems[] = "The field map writes '{$column}', which isn't a column on '{$this->target_table}'.";
            }
        }

        return $problems;
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    /**
     * Only meaningful when the host sets connect.consumer.event_model.
     */
    public function localEvent(): BelongsTo
    {
        return $this->belongsTo(config('connect.consumer.event_model') ?? self::class, 'local_event_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(SyncIssue::class);
    }

    public function openIssues(): HasMany
    {
        return $this->issues()->whereNull('resolved_at');
    }

    /**
     * Uses an eager-loaded open_issues_count (withCount(['openIssues'])) when present.
     */
    public function openIssueCount(): int
    {
        return $this->open_issues_count ?? $this->openIssues()->count();
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
     * Eager-load latestRun and latestSuccessfulRun, and withCount openIssues,
     * when calling this in a list.
     */
    public function health(): SyncHealth
    {
        if (! $this->enabled) {
            return SyncHealth::Off;
        }

        if (in_array($this->latestRun?->status, [SyncRunStatus::Failed, SyncRunStatus::Partial], true)) {
            return SyncHealth::Failing;
        }

        // An incremental run won't re-read a row held earlier, so a clean
        // latest run alone doesn't mean nothing is waiting.
        if ($this->openIssueCount() > 0) {
            return SyncHealth::Failing;
        }

        $staleAfter = (int) config('connect.consumer.stale_after_minutes', 120);

        if ($this->lastSyncedAt() === null || $this->lastSyncedAt()->lt(now()->subMinutes($staleAfter))) {
            return SyncHealth::Overdue;
        }

        if (filled($this->latestRun?->warnings)) {
            return SyncHealth::Attention;
        }

        return SyncHealth::Healthy;
    }
}
