<?php

namespace Jma\Connect\Contracts;

use Jma\Connect\Consumer\Models\SyncMapping;
use Jma\Connect\Data\AttendeeData;
use Jma\Connect\Enums\WriteResult;

/**
 * Implemented by a host app in consumer mode: write one canonical attendee
 * into the host's own model. Match on the mapping's local event plus a
 * stable external reference (the DTO id), never on email alone. When
 * $attendee->isDeleted(), remove or skip the local record. Only update a
 * column when $attendee->has() its wire key: an absent key means the source
 * didn't send that field, not that it's empty. The job has already rejected
 * live attendees without a first and last name.
 */
interface AttendeeSink
{
    public function write(SyncMapping $mapping, AttendeeData $attendee): WriteResult;
}
