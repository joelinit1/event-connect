<?php

namespace Jma\Connect\Contracts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Jma\Connect\Data\AttendeeData;
use Jma\Connect\Data\EventData;
use Jma\Connect\Provider\Models\ApiClient;

/**
 * Implemented by a host app in provider mode: map the host's own models to
 * the suite's canonical shapes. Return paginators whose items are DTOs
 * (e.g. `->paginate($perPage)->through(fn ($m) => new EventData(...))`).
 * When $updatedSince is given, include soft-deleted rows with deletedAt set
 * so consumers receive tombstones.
 */
interface AttendeeProvider
{
    /**
     * @return LengthAwarePaginator<int, EventData>
     */
    public function events(ApiClient $client, ?CarbonImmutable $updatedSince, int $perPage): LengthAwarePaginator;

    /**
     * @return LengthAwarePaginator<int, AttendeeData>
     */
    public function attendees(ApiClient $client, string $eventId, ?CarbonImmutable $updatedSince, int $perPage): LengthAwarePaginator;
}
