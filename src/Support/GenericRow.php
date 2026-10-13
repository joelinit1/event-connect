<?php

namespace Jma\Connect\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A row of a table the host has no model for, so both cases are written
 * the same way. Timestamps are kept only for the columns the table has.
 */
class GenericRow extends Model
{
    protected $guarded = [];

    private ?string $createdAtColumn = null;

    private ?string $updatedAtColumn = null;

    public static function forTable(string $table, ?string $fallbackKey = null): static
    {
        $row = new static;
        $row->configure($table, $fallbackKey);

        return $row;
    }

    public function newInstance($attributes = [], $exists = false): static
    {
        $instance = parent::newInstance($attributes, $exists);
        $instance->configure($this->getTable(), $this->getKeyName());

        return $instance;
    }

    private function configure(string $table, ?string $fallbackKey): void
    {
        $columns = LocalTables::columns($table);
        $primary = LocalTables::primaryKey($table);

        $this->setTable($table);
        $this->setKeyName($primary['name'] ?? $fallbackKey ?? 'id');
        $this->setIncrementing($primary['auto'] ?? false);
        $this->setKeyType($primary['auto'] ?? false ? 'int' : 'string');
        $this->createdAtColumn = in_array('created_at', $columns, true) ? 'created_at' : null;
        $this->updatedAtColumn = in_array('updated_at', $columns, true) ? 'updated_at' : null;
        $this->timestamps = $this->createdAtColumn !== null || $this->updatedAtColumn !== null;
    }

    public function getCreatedAtColumn(): ?string
    {
        return $this->createdAtColumn;
    }

    public function getUpdatedAtColumn(): ?string
    {
        return $this->updatedAtColumn;
    }
}
