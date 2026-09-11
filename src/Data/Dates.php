<?php

namespace Jma\Connect\Data;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class Dates
{
    public static function parse(DateTimeInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->utc();
    }

    public static function format(?CarbonImmutable $value): ?string
    {
        return $value?->toIso8601String();
    }
}
