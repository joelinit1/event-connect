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
                // Only when the host has tenants: a hidden relationship field still
                // loads its relation, which has no model class without one.
                ...(filled(config('connect.provider.tenant_model')) ? [
                    Select::make('tenant_id')
                        ->label('Client')
                        ->relationship('tenant', config('connect.provider.tenant_label', 'name'))
                        ->searchable()
                        ->preload(),
                ] : []),
                ...TokenFields::components(),
            ]);
    }
}
