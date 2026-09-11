<?php

namespace Jma\Connect\Data;

use Carbon\CarbonImmutable;

final readonly class AttendeeData
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $id,
        public string $firstName,
        public string $lastName,
        public ?string $email = null,
        public ?string $company = null,
        public ?string $jobTitle = null,
        public ?string $table = null,
        public ?string $seat = null,
        public ?string $zone = null,
        public ?CarbonImmutable $checkedInAt = null,
        public ?CarbonImmutable $updatedAt = null,
        public ?CarbonImmutable $deletedAt = null,
        public array $extra = [],
    ) {}

    /**
     * @param  array{id: string|int, first_name?: ?string, last_name?: ?string, email?: ?string, company?: ?string, job_title?: ?string, table?: ?string, seat?: ?string, zone?: ?string, checked_in_at?: ?string, updated_at?: ?string, deleted_at?: ?string, extra?: ?array<string, mixed>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            firstName: $data['first_name'] ?? '',
            lastName: $data['last_name'] ?? '',
            email: $data['email'] ?? null,
            company: $data['company'] ?? null,
            jobTitle: $data['job_title'] ?? null,
            table: $data['table'] ?? null,
            seat: $data['seat'] ?? null,
            zone: $data['zone'] ?? null,
            checkedInAt: Dates::parse($data['checked_in_at'] ?? null),
            updatedAt: Dates::parse($data['updated_at'] ?? null),
            deletedAt: Dates::parse($data['deleted_at'] ?? null),
            extra: $data['extra'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->email,
            'company' => $this->company,
            'job_title' => $this->jobTitle,
            'table' => $this->table,
            'seat' => $this->seat,
            'zone' => $this->zone,
            'checked_in_at' => Dates::format($this->checkedInAt),
            'updated_at' => Dates::format($this->updatedAt),
            'deleted_at' => Dates::format($this->deletedAt),
            'extra' => $this->extra,
        ];
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
