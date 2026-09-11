<?php

namespace Jma\Connect\Contracts;

use Jma\Connect\Consumer\Models\SyncMapping;
use Jma\Connect\Data\AttendeeData;
use Jma\Connect\Enums\WriteResult;

/**
 * Implemented by a host app in consumer mode: write one canonical attendee
 * into the host's own model. Match on the mapping's local event plus a
 * stable external reference (the DTO id), never on email alone. When
 * $attendee->isDeleted(), remove or skip the local record.
 */
interface AttendeeSink
{
    public function write(SyncMapping $mapping, AttendeeData $attendee): WriteResult;
}
