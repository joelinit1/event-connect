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
     * True unless the host named its own AttendeeProvider class, i.e.
     * attendees are served from the Attendee Sources screen.
     */
    public static function usesBuiltInProvider(): bool
    {
        return in_array(config('connect.provider.class'), [null, Provider\TableAttendeeProvider::class], true);
    }

    /**
     * True unless the host named its own AttendeeSink class, i.e. each event
     * mapping carries its own destination table and field map.
     */
    public static function usesBuiltInSink(): bool
    {
        return in_array(config('connect.consumer.sink'), [null, Consumer\Sinks\TableAttendeeSink::class], true);
    }

    /**
     * The fallback for the connect.manage Gate when the host doesn't define
     * one: the user's email is in connect.admin_emails (CONNECT_ADMIN_EMAILS).
     */
    public static function isAdminEmail(?string $email): bool
    {
        $admins = config('connect.admin_emails');
        $admins = is_string($admins) ? explode(',', $admins) : (array) $admins;
        $admins = array_filter(array_map(fn ($a) => mb_strtolower(trim((string) $a)), $admins));

        return $email !== null && in_array(mb_strtolower(trim($email)), $admins, true);
    }

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
