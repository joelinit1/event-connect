<?php

namespace Jma\Connect\Consumer\Sources;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use Jma\Connect\Connect;
use Jma\Connect\Consumer\Models\Connection;
use Jma\Connect\Contracts\Source;
use Jma\Connect\Data\AttendeeData;
use Jma\Connect\Data\EventData;

/**
 * Talks to another app running this package in provider mode.
 */
class SuiteSource implements Source
{
    private const PER_PAGE = 200;

    public function __construct(private readonly Connection $connection) {}

    public function events(): Collection
    {
        return collect(iterator_to_array($this->pages('events'), preserve_keys: false))
            ->map(fn (array $row) => EventData::fromArray($row));
    }

    public function attendees(string $remoteEventId, ?CarbonImmutable $updatedSince): LazyCollection
    {
        $query = $updatedSince ? ['updated_since' => $updatedSince->toIso8601String()] : [];

        return LazyCollection::make(fn () => yield from $this->pages("events/{$remoteEventId}/attendees", $query))
            ->map(fn (array $row) => AttendeeData::fromArray($row));
    }

    public function test(): void
    {
        $this->request()->get($this->url('events'), ['per_page' => 1])->throw();
    }

    /**
     * @param  array<string, string>  $query
     * @return Generator<int, array<string, mixed>>
     */
    private function pages(string $path, array $query = []): Generator
    {
        $url = $this->url($path);
        $query['per_page'] = self::PER_PAGE;

        while ($url) {
            $response = $this->request()->get($url, $query)->throw();

            foreach ($response->json('data', []) as $row) {
                yield $row;
            }

            // The next link already carries the query string.
            $url = $response->json('links.next');
            $query = [];
        }
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->connection->token)
            ->acceptJson()
            ->timeout(30)
            ->retry(2, 1000, throw: false);
    }

    private function url(string $path): string
    {
        return rtrim($this->connection->base_url, '/').'/'.Connect::API_PREFIX.'/'.$path;
    }
}
