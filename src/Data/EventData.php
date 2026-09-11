<?php

namespace Jma\Connect\Data;

use Carbon\CarbonImmutable;

final readonly class EventData
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $slug = null,
        public ?CarbonImmutable $startsAt = null,
        public ?CarbonImmutable $endsAt = null,
        public ?CarbonImmutable $updatedAt = null,
    ) {}

    /**
     * @param  array{id: string|int, name: string, slug?: ?string, starts_at?: ?string, ends_at?: ?string, updated_at?: ?string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            name: $data['name'],
            slug: $data['slug'] ?? null,
            startsAt: Dates::parse($data['starts_at'] ?? null),
            endsAt: Dates::parse($data['ends_at'] ?? null),
            updatedAt: Dates::parse($data['updated_at'] ?? null),
        );
    }

    /**
     * @return array{id: string, name: string, slug: ?string, starts_at: ?string, ends_at: ?string, updated_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'starts_at' => Dates::format($this->startsAt),
            'ends_at' => Dates::format($this->endsAt),
            'updated_at' => Dates::format($this->updatedAt),
        ];
    }
}
