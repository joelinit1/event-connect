<?php

namespace Jma\Connect\Provider\Filament\Resources\ApiClients\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ApiClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->helperText('Which app this token is for, e.g. "Seating Plan (production)".')
                    ->required(),
                Select::make('tenant_id')
                    ->label('Client')
                    ->relationship('tenant', config('connect.provider.tenant_label', 'name'))
                    ->searchable()
                    ->preload()
                    ->visible(fn () => filled(config('connect.provider.tenant_model'))),
                ...TokenFields::components(),
            ]);
    }
}
