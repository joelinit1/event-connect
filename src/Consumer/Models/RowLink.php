<?php

namespace Jma\Connect\Consumer\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Which local row a remote attendee was written to, for destination tables
 * with no column of their own to hold the remote ID. Kept by the package so
 * the host table needs no migration.
 */
class RowLink extends Model
{
    protected $table = 'connect_row_links';

    protected $fillable = ['sync_mapping_id', 'remote_id', 'local_key'];
}
