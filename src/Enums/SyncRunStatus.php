<?php

namespace Jma\Connect\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SyncRunStatus: string implements HasColor, HasLabel
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Partial = 'partial';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Succeeded => 'Succeeded',
            self::Partial => 'Partial (row errors)',
            self::Failed => 'Failed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Running => 'info',
            self::Succeeded => 'success',
            self::Partial => 'warning',
            self::Failed => 'danger',
        };
    }
}
