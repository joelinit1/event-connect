<?php

namespace Jma\Connect\Consumer\Sinks;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Jma\Connect\Consumer\Models\RowLink;
use Jma\Connect\Consumer\Models\SyncMapping;
use Jma\Connect\Contracts\AttendeeSink;
use Jma\Connect\Data\AttendeeData;
use Jma\Connect\Enums\WriteResult;
use Jma\Connect\Exceptions\MappingProblem;
use Jma\Connect\Support\GenericRow;
use Jma\Connect\Support\LocalModels;

/**
 * The sink used when the host doesn't name its own: writes each attendee
 * into the table chosen on its event mapping, through that mapping's field
 * map, and never matches on email.
 *
 * Rows are written through the app's own model for the table when it has
 * one, so its hooks, casts and soft deletes apply exactly as when the app
 * creates a row itself. A row is matched on the remote ID column if one is
 * set, or else on the link recorded when the package created it.
 */
class TableAttendeeSink implements AttendeeSink
{
    public function write(SyncMapping $mapping, AttendeeData $attendee): WriteResult
    {
        if ($problems = $mapping->destinationProblems()) {
            throw new MappingProblem(implode(' ', $problems));
        }

        $existing = $this->find($mapping, $attendee->id);

        if ($attendee->isDeleted()) {
            return $existing === null ? WriteResult::Skipped : $this->delete($mapping, $existing);
        }

        $row = $existing ?? $this->newRow($mapping);
        $row->forceFill($this->values($mapping, $row, $attendee));

        if ($existing === null) {
            $row->forceFill(array_filter([
                $mapping->target_key_column => $attendee->id,
                $mapping->target_event_column => $mapping->local_event_id,
            ], fn ($value, $column) => filled($column), ARRAY_FILTER_USE_BOTH));
        } elseif ($this->isTrashed($mapping, $row)) {
            $row->{$this->deletedAtColumn($row)} = null;
        }

        if ($existing !== null && ! $row->isDirty()) {
            return WriteResult::Skipped;
        }

        $row->save();

        if ($existing === null && blank($mapping->target_key_column)) {
            RowLink::updateOrCreate(
                ['sync_mapping_id' => $mapping->id, 'remote_id' => $attendee->id],
                ['local_key' => (string) $row->getKey()],
            );
        }

        return $existing === null ? WriteResult::Created : WriteResult::Updated;
    }

    /**
     * The local row this remote attendee was written to, trashed or not.
     */
    public function find(SyncMapping $mapping, string $remoteId): ?Model
    {
        if (filled($mapping->target_key_column)) {
            return $this->query($mapping)
                ->where($mapping->target_key_column, $remoteId)
                ->when(filled($mapping->target_event_column), fn (Builder $query) => $query->where($mapping->target_event_column, $mapping->local_event_id))
                ->first();
        }

        $key = RowLink::where(['sync_mapping_id' => $mapping->id, 'remote_id' => $remoteId])->value('local_key');

        return $key === null ? null : $this->query($mapping)->whereKey($key)->first();
    }

    /**
     * Remote IDs of the live (not deleted) local rows this mapping wrote:
     * the ones a sync may remove when the source stops sending them. Rows
     * added by hand have no remote ID or link, so they're never included.
     *
     * @return list<string>
     */
    public function syncedRemoteIds(SyncMapping $mapping): array
    {
        $row = $this->newRow($mapping);
        $deletedAt = match (true) {
            $this->usesSoftDeletes($row) => $row->getDeletedAtColumn(),
            $mapping->on_delete === 'soft_delete' => 'deleted_at',
            default => null,
        };
        $live = fn () => $this->query($mapping)->when($deletedAt, fn (Builder $query) => $query->whereNull($deletedAt));

        if (filled($mapping->target_key_column)) {
            return $live()
                ->whereNotNull($mapping->target_key_column)
                ->when(filled($mapping->target_event_column), fn (Builder $query) => $query->where($mapping->target_event_column, $mapping->local_event_id))
                ->pluck($mapping->target_key_column)
                ->map(fn ($id) => (string) $id)
                ->all();
        }

        // Links to rows that were since deleted are left out by the query.
        $links = RowLink::where('sync_mapping_id', $mapping->id)->pluck('remote_id', 'local_key');

        return $links->keys()->chunk(1000)
            ->flatMap(fn ($keys) => $live()->whereKey($keys->all())->pluck($row->getKeyName()))
            ->map(fn ($key) => (string) $links[(string) $key])
            ->values()
            ->all();
    }

    /**
     * The app's model class for the destination, or null for a plain table.
     *
     * @return class-string<Model>|null
     */
    public static function modelFor(SyncMapping $mapping): ?string
    {
        return LocalModels::for($mapping->target_table);
    }

    private function delete(SyncMapping $mapping, Model $row): WriteResult
    {
        if ($mapping->on_delete === 'ignore' || $this->isTrashed($mapping, $row)) {
            return WriteResult::Skipped;
        }

        if ($mapping->on_delete === 'soft_delete' && ! $this->usesSoftDeletes($row)) {
            $row->forceFill(['deleted_at' => $row->freshTimestampString()])->save();
        } else {
            // The app's own delete: a model with SoftDeletes soft-deletes.
            $row->delete();
        }

        return WriteResult::Deleted;
    }

    /**
     * Mapped values, with arrays left as arrays where the model casts the
     * column (it encodes them itself) and JSON-encoded everywhere else.
     *
     * @return array<string, mixed>
     */
    private function values(SyncMapping $mapping, Model $row, AttendeeData $attendee): array
    {
        $values = $mapping->fieldMap()->apply($attendee->toArray());

        foreach ($values as $column => $value) {
            if (is_array($value) && ! $row->hasCast($column)) {
                $values[$column] = json_encode($value);
            }
        }

        return $values;
    }

    private function newRow(SyncMapping $mapping): Model
    {
        $class = self::modelFor($mapping);

        return $class !== null ? new $class : GenericRow::forTable($mapping->target_table, $mapping->target_key_column);
    }

    /**
     * Global scopes off: a tenant or "active only" scope must not hide a
     * row the sync wrote, or it would write it a second time.
     */
    private function query(SyncMapping $mapping): Builder
    {
        return $this->newRow($mapping)->newQueryWithoutScopes();
    }

    private function isTrashed(SyncMapping $mapping, Model $row): bool
    {
        $column = $this->deletedAtColumn($row);

        return ($this->usesSoftDeletes($row) || $mapping->on_delete === 'soft_delete')
            && $row->getAttribute($column) !== null;
    }

    private function usesSoftDeletes(Model $row): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($row), true);
    }

    private function deletedAtColumn(Model $row): string
    {
        return $this->usesSoftDeletes($row) ? $row->getDeletedAtColumn() : 'deleted_at';
    }
}
