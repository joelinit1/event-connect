<?php

namespace Jma\Connect\Provider\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Jma\Connect\Data\AttendeeData;
use Jma\Connect\Data\Dates;
use Jma\Connect\Mapping\FieldMap;
use Jma\Connect\Support\LocalTables;
use Throwable;

/**
 * One event's attendees as exposed by the built-in TableAttendeeProvider:
 * which local table they live in, how to pick this event's rows out of it,
 * and how its columns map onto the wire format. All set in Filament, so a
 * new event with a new table layout needs no code.
 */
class AttendeeSource extends Model
{
    protected $table = 'connect_attendee_sources';

    protected $fillable = [
        'tenant_id', 'name', 'slug', 'enabled', 'table_name', 'filter_column', 'filter_value',
        'id_column', 'updated_at_column', 'deleted_at_column', 'field_map',
    ];

    protected $attributes = [
        'id_column' => 'id',
        'enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'field_map' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(config('connect.provider.tenant_model'));
    }

    /**
     * Sources an API client may read: all of them, or only its tenant's.
     *
     * @param  EloquentBuilder<self>  $query
     */
    public function scopeVisibleTo(EloquentBuilder $query, ApiClient $client): void
    {
        $query->where('enabled', true)->when(
            filled(config('connect.provider.tenant_model')) && $client->tenant_id !== null,
            fn ($query) => $query->where('tenant_id', $client->tenant_id),
        );
    }

    public function fieldMap(): FieldMap
    {
        return new FieldMap($this->field_map);
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        return LocalTables::columns($this->table_name);
    }

    /**
     * Problems that stop this source being read at all.
     *
     * @return list<string>
     */
    public function configurationProblems(): array
    {
        $columns = $this->columns();

        if ($columns === []) {
            return ["Table '{$this->table_name}' doesn't exist."];
        }

        $problems = [];

        foreach (['id_column', 'filter_column', 'updated_at_column', 'deleted_at_column'] as $setting) {
            if (filled($this->{$setting}) && ! in_array($this->{$setting}, $columns, true)) {
                $problems[] = "Column '{$this->{$setting}}' ({$setting}) isn't on table '{$this->table_name}'.";
            }
        }

        foreach ($this->fieldMap()->sources() as $path) {
            $column = explode('.', $path)[0];

            if (! in_array($column, $columns, true)) {
                $problems[] = "Field map reads '{$path}', but table '{$this->table_name}' has no '{$column}' column.";
            }
        }

        return $problems;
    }

    public function rows(): Builder
    {
        if ($problems = $this->configurationProblems()) {
            throw new InvalidArgumentException("Attendee source '{$this->name}' is misconfigured: ".implode(' ', $problems));
        }

        return DB::table($this->table_name)
            ->when(filled($this->filter_column), fn (Builder $query) => $query->where($this->filter_column, $this->filter_value));
    }

    /**
     * Live rows on a full read; everything changed since, tombstones
     * included, on an incremental one. Without an updated-at column every
     * read is a full one.
     */
    public function attendeeRows(?CarbonImmutable $updatedSince): Builder
    {
        $query = $this->rows()->orderBy($this->id_column);

        if ($updatedSince !== null && filled($this->updated_at_column)) {
            return $query->where(fn (Builder $q) => $q
                ->where($this->updated_at_column, '>=', $updatedSince->format('Y-m-d H:i:s'))
                ->when(filled($this->deleted_at_column), fn (Builder $q) => $q
                    ->orWhere($this->deleted_at_column, '>=', $updatedSince->format('Y-m-d H:i:s'))));
        }

        return $query->when(filled($this->deleted_at_column), fn (Builder $q) => $q->whereNull($this->deleted_at_column));
    }

    /**
     * @param  array<string, mixed>|object  $row
     */
    public function toAttendee(array|object $row): AttendeeData
    {
        $row = (array) $row;
        $data = ['id' => (string) $row[$this->id_column]];

        if (filled($this->updated_at_column)) {
            $data['updated_at'] = self::date($row[$this->updated_at_column] ?? null);
        }

        $deletedAt = filled($this->deleted_at_column) ? self::date($row[$this->deleted_at_column] ?? null) : null;

        if ($deletedAt !== null) {
            return AttendeeData::fromArray([...$data, 'deleted_at' => $deletedAt]);
        }

        $extra = [];

        foreach ($this->fieldMap()->apply($row) as $key => $value) {
            if ($key === 'extra') {
                $extra = [...$extra, ...(is_array($value) ? $value : ['value' => $value])];
            } elseif ($key === 'checked_in_at') {
                $data[$key] = self::date($value);
            } elseif (in_array($key, AttendeeData::WIRE_FIELDS, true)) {
                $data[$key] = self::text($value);
            } else {
                // Not a named wire field: carry it as an extra rather than drop it.
                $extra[$key] = $value;
            }
        }

        if ($extra !== []) {
            $data['extra'] = $extra;
        }

        return AttendeeData::fromArray($data);
    }

    private static function text(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => json_encode($value),
        };
    }

    private static function date(mixed $value): ?string
    {
        try {
            return Dates::format(Dates::parse(is_scalar($value) ? (string) $value : null));
        } catch (Throwable) {
            return null;
        }
    }
}
