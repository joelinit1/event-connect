<?php

namespace Jma\Connect\Provider\Filament\Resources\ApiClients\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Jma\Connect\Connect;

/**
 * Shared by the create form and the "Issue new token" row action so both
 * issue tokens identically.
 */
class TokenFields
{
    /**
     * @return array<int, CheckboxList|DateTimePicker>
     */
    public static function components(): array
    {
        return [
            CheckboxList::make('abilities')
                ->options(Connect::abilities())
                ->default(array_keys(Connect::abilities()))
                ->required(),
            DateTimePicker::make('expires_at')
                ->label('Expires')
                ->helperText('Leave blank for a token that never expires.')
                ->default(now()->addYear()),
        ];
    }

    public static function notifyIssued(string $plainTextToken): void
    {
        Notification::make()
            ->title('API token issued')
            ->body("Copy it now — it will not be shown again:\n\n{$plainTextToken}")
            ->success()
            ->persistent()
            ->send();
    }
}
