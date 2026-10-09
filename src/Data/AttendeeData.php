<?php

namespace Jma\Connect\Data;

use Carbon\CarbonImmutable;
use Jma\Connect\Exceptions\InvalidAttendeeData;

final readonly class AttendeeData
{
    public const REQUIRED = ['first_name'];

    /**
     * @param  array<string, mixed>  $extra
     * @param  list<string>|null  $fields  Wire keys the source actually sent;
     *                                     null (built in code) means all of them.
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
        public ?string $bookingRef = null,
        public ?string $category = null,
        public ?string $checkInCode = null,
        public ?CarbonImmutable $checkedInAt = null,
        public ?CarbonImmutable $updatedAt = null,
        public ?CarbonImmutable $deletedAt = null,
        public array $extra = [],
        public ?array $fields = null,
    ) {}

    /**
     * @param  array{id: string|int, first_name?: ?string, last_name?: ?string, email?: ?string, company?: ?string, job_title?: ?string, table?: ?string, seat?: ?string, zone?: ?string, booking_ref?: ?string, category?: ?string, check_in_code?: ?string, checked_in_at?: ?string, updated_at?: ?string, deleted_at?: ?string, extra?: ?array<string, mixed>}  $data
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
            bookingRef: $data['booking_ref'] ?? null,
            category: $data['category'] ?? null,
            checkInCode: $data['check_in_code'] ?? null,
            checkedInAt: Dates::parse($data['checked_in_at'] ?? null),
            updatedAt: Dates::parse($data['updated_at'] ?? null),
            deletedAt: Dates::parse($data['deleted_at'] ?? null),
            extra: $data['extra'] ?? [],
            fields: array_keys($data),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->email,
            'company' => $this->company,
            'job_title' => $this->jobTitle,
            'table' => $this->table,
            'seat' => $this->seat,
            'zone' => $this->zone,
            'booking_ref' => $this->bookingRef,
            'category' => $this->category,
            'check_in_code' => $this->checkInCode,
            'checked_in_at' => Dates::format($this->checkedInAt),
            'updated_at' => Dates::format($this->updatedAt),
            'deleted_at' => Dates::format($this->deletedAt),
            'extra' => $this->extra,
        ];

        return $this->fields === null
            ? $data
            : array_intersect_key($data, array_flip(['id', ...$this->fields]));
    }

    /**
     * Whether the source sent this wire key at all, as distinct from sending
     * it as null. Sinks should leave a local column alone when this is false,
     * so a source that doesn't know a field (or an older package version that
     * predates it) never blanks data held here.
     */
    public function has(string $field): bool
    {
        return $this->fields === null || in_array($field, $this->fields, true);
    }

    /**
     * Reject a live attendee without a first name rather than let a sink
     * overwrite real names with blanks. Last name is optional so single-name
     * people sync. Tombstones only need an id.
     *
     * @throws InvalidAttendeeData
     */
    public function assertValid(): void
    {
        if ($this->isDeleted()) {
            return;
        }

        $values = ['first_name' => $this->firstName];

        $missing = array_filter(
            self::REQUIRED,
            fn (string $field) => ! $this->has($field) || trim($values[$field]) === '',
        );

        if ($missing !== []) {
            throw new InvalidAttendeeData('Missing required '.implode(', ', $missing).'.');
        }
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
