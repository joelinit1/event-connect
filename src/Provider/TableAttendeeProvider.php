<?php

namespace Jma\Connect\Provider;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Jma\Connect\Contracts\AttendeeProvider;
use Jma\Connect\Data\EventData;
use Jma\Connect\Provider\Models\ApiClient;
use Jma\Connect\Provider\Models\AttendeeSource;

/**
 * The provider used when the host doesn't name its own: serves each
 * AttendeeSource configured on the Attendee Sources screen as an event.
 */
class TableAttendeeProvider implements AttendeeProvider
{
    public function events(ApiClient $client, ?CarbonImmutable $updatedSince, int $perPage): LengthAwarePaginator
    {
        return AttendeeSource::query()
            ->visibleTo($client)
            ->when($updatedSince, fn ($query) => $query->where('updated_at', '>=', $updatedSince))
            ->orderBy('id')
            ->paginate($perPage)
            ->through(fn (AttendeeSource $source) => new EventData(
                id: (string) $source->id,
                name: $source->name,
                slug: $source->slug,
                updatedAt: $source->updated_at?->toImmutable(),
            ));
    }

    public function attendees(ApiClient $client, string $eventId, ?CarbonImmutable $updatedSince, int $perPage): LengthAwarePaginator
    {
        $source = AttendeeSource::query()->visibleTo($client)->findOrFail($eventId);

        return $source->attendeeRows($updatedSince)
            ->paginate($perPage)
            ->through(fn (object $row) => $source->toAttendee($row));
    }
}
