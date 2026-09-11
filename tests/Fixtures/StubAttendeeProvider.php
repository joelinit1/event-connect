<?php

namespace Jma\Connect\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as PaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Jma\Connect\Contracts\AttendeeProvider;
use Jma\Connect\Data\AttendeeData;
use Jma\Connect\Data\EventData;
use Jma\Connect\Provider\Models\ApiClient;

class StubAttendeeProvider implements AttendeeProvider
{
    /** @var array<string, mixed>|null */
    public static ?array $lastCall = null;

    public static function reset(): void
    {
        self::$lastCall = null;
    }

    public function events(ApiClient $client, ?CarbonImmutable $updatedSince, int $perPage): PaginatorContract
    {
        self::$lastCall = compact('client', 'updatedSince', 'perPage');

        return $this->paginate([
            new EventData(id: '1', name: 'Spring Gala', slug: 'spring-gala', updatedAt: CarbonImmutable::parse('2026-01-01T10:00:00Z')),
            new EventData(id: '2', name: 'Summer Party'),
            new EventData(id: '3', name: 'Winter Ball'),
        ], $perPage);
    }

    public function attendees(ApiClient $client, string $eventId, ?CarbonImmutable $updatedSince, int $perPage): PaginatorContract
    {
        self::$lastCall = compact('client', 'eventId', 'updatedSince', 'perPage');

        return $this->paginate([
            new AttendeeData(id: 'a1', firstName: 'Ada', lastName: 'Lovelace', email: 'ada@example.com', table: 'T1'),
            new AttendeeData(id: 'a2', firstName: 'Grace', lastName: 'Hopper', deletedAt: CarbonImmutable::parse('2026-02-01T09:00:00Z')),
        ], $perPage);
    }

    /**
     * @param  list<EventData|AttendeeData>  $items
     */
    private function paginate(array $items, int $perPage): LengthAwarePaginator
    {
        $page = Paginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            array_slice($items, ($page - 1) * $perPage, $perPage),
            count($items),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }
}
