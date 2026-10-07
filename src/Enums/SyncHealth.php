<?php

namespace Jma\Connect\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Worked out on read, never stored: a stopped scheduler or queue worker
 * writes nothing, so only "how long since the last good run" can reveal it.
 * Cases are ordered best to worst — see worst().
 */
enum SyncHealth: string implements HasColor, HasDescription, HasLabel
{
    case Off = 'off';
    case Healthy = 'healthy';
    case Overdue = 'overdue';
    case Failing = 'failing';

    /**
     * @param  iterable<self>  $healths
     */
    public static function worst(iterable $healths): self
    {
        $order = array_flip(array_map(fn (self $case) => $case->value, self::cases()));

        return collect($healths)->sortByDesc(fn (self $health) => $order[$health->value])->first() ?? self::Off;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::Healthy => 'Healthy',
            self::Overdue => 'Overdue',
            self::Failing => 'Failing',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Off => 'gray',
            self::Healthy => 'success',
            self::Overdue => 'warning',
            self::Failing => 'danger',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Off => 'Syncing is switched off for this event.',
            self::Healthy => 'Syncing on schedule.',
            self::Overdue => 'No successful sync recently — the scheduler or queue worker may have stopped.',
            self::Failing => 'The last sync failed or had row errors — see Sync history.',
        };
    }
}
