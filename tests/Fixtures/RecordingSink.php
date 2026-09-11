<?php

namespace Jma\Connect\Tests\Fixtures;

use Closure;
use Jma\Connect\Consumer\Models\SyncMapping;
use Jma\Connect\Contracts\AttendeeSink;
use Jma\Connect\Data\AttendeeData;
use Jma\Connect\Enums\WriteResult;

class RecordingSink implements AttendeeSink
{
    /** @var list<array{mapping: SyncMapping, attendee: AttendeeData}> */
    public static array $written = [];

    /** @var (Closure(AttendeeData): WriteResult)|null */
    public static ?Closure $behaviour = null;

    public static function reset(): void
    {
        self::$written = [];
        self::$behaviour = null;
    }

    public function write(SyncMapping $mapping, AttendeeData $attendee): WriteResult
    {
        self::$written[] = compact('mapping', 'attendee');

        return self::$behaviour ? (self::$behaviour)($attendee) : WriteResult::Created;
    }
}
