<?php

namespace Jma\Connect\Consumer\Sinks;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Jma\Connect\Consumer\Models\SyncMapping;
use Jma\Connect\Contracts\AttendeeSink;
use Jma\Connect\Data\AttendeeData;
use Jma\Connect\Enums\WriteResult;
use Jma\Connect\Exceptions\MappingProblem;
use Jma\Connect\Support\LocalTables;

/**
 * The sink used when the host doesn't name its own: writes each attendee
 * into the table chosen on its event mapping, through that mapping's field
 * map. Rows are matched on the remote ID column (plus the event column, if
 * set), never on email.
 */
class TableAttendeeSink implements AttendeeSink
{
    public function write(SyncMapping $mapping, AttendeeData $attendee): WriteResult
    {
        if ($problems = $mapping->destinationProblems()) {
            throw new MappingProblem(implode(' ', $problems));
        }

        $columns = LocalTables::columns($mapping->target_table);
        $stamp = fn (array $values, string $column) => in_array($column, $columns, true) ? [...$values, $column => now()] : $values;

        if ($attendee->isDeleted()) {
            return $this->delete($mapping, $attendee, $stamp);
        }

        $values = array_map(
            fn (mixed $value) => is_array($value) ? json_encode($value) : $value,
            $mapping->fieldMap()->apply($attendee->toArray()),
        );

        $existing = $this->match($mapping, $attendee)->first();

        if ($existing === null) {
            $this->table($mapping)->insert($stamp($stamp([
                ...$values,
                $mapping->target_key_column => $attendee->id,
                ...(filled($mapping->target_event_column) ? [$mapping->target_event_column => $mapping->local_event_id] : []),
            ], 'created_at'), 'updated_at'));

            return WriteResult::Created;
        }

        // Loose comparison: the database hands back '5' for a 5 written earlier.
        $changed = array_filter($values, fn (mixed $value, string $column) => $existing->{$column} != $value, ARRAY_FILTER_USE_BOTH);

        if ($mapping->on_delete === 'soft_delete' && ($existing->deleted_at ?? null) !== null) {
            $changed['deleted_at'] = null;
        }

        if ($changed === []) {
            return WriteResult::Skipped;
        }

        $this->match($mapping, $attendee)->update($stamp($changed, 'updated_at'));

        return WriteResult::Updated;
    }

    private function delete(SyncMapping $mapping, AttendeeData $attendee, callable $stamp): WriteResult
    {
        $affected = match ($mapping->on_delete) {
            'ignore' => 0,
            'soft_delete' => $this->match($mapping, $attendee)->whereNull('deleted_at')->update($stamp(['deleted_at' => now()], 'updated_at')),
            default => $this->match($mapping, $attendee)->delete(),
        };

        return $affected > 0 ? WriteResult::Deleted : WriteResult::Skipped;
    }

    private function match(SyncMapping $mapping, AttendeeData $attendee): Builder
    {
        return $this->table($mapping)
            ->where($mapping->target_key_column, $attendee->id)
            ->when(filled($mapping->target_event_column), fn (Builder $query) => $query->where($mapping->target_event_column, $mapping->local_event_id));
    }

    private function table(SyncMapping $mapping): Builder
    {
        return DB::table($mapping->target_table);
    }
}
