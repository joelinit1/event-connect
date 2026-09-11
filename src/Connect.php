<?php

namespace Jma\Connect;

final class Connect
{
    /**
     * Fixed on both sides of the wire so a consumer never has to be told a
     * provider's prefix; bump the version segment, never make it configurable.
     */
    public const API_PREFIX = 'api/v1';

    public const GATE = 'connect.manage';

    public const ABILITY_EVENTS_READ = 'events:read';

    public const ABILITY_ATTENDEES_READ = 'attendees:read';

    /**
     * @return array<string, string>
     */
    public static function abilities(): array
    {
        return [
            self::ABILITY_EVENTS_READ => 'Read events',
            self::ABILITY_ATTENDEES_READ => 'Read attendees',
        ];
    }
}
