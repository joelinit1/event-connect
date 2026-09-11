<?php

namespace Jma\Connect\Contracts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Jma\Connect\Data\AttendeeData;
use Jma\Connect\Data\EventData;

/**
 * One implementation per driver. Instances are built per Connection via
 * Connection::source(), receiving the connection as `$connection`.
 */
interface Source
{
    /**
     * @return Collection<int, EventData>
     */
    public function events(): Collection;

    /**
     * @return LazyCollection<int, AttendeeData>
     */
    public function attendees(string $remoteEventId, ?CarbonImmutable $updatedSince): LazyCollection;

    /**
     * Throw on any auth or connectivity failure.
     */
    public function test(): void;
}
