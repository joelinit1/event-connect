<?php

namespace Jma\Connect\Consumer\Filament\Resources\Connections\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ConnectionForm
{
    public static function configure(Schema $schema): Schema
    {
        $drivers = array_keys(config('connect.consumer.drivers', []));

        return $schema
            ->components([
                // Only when the host has tenants: a hidden relationship field still
                // loads its relation, which has no model class without one.
                ...(filled(config('connect.consumer.tenant_model')) ? [
                    Select::make('tenant_id')
                        ->label('Client')
                        ->relationship('tenant', config('connect.consumer.tenant_label', 'name'))
                        ->searchable()
                        ->preload()
                        ->required(),
                ] : []),
                TextInput::make('name')
                    ->helperText('e.g. "LLS Registration"')
                    ->required(),
                Select::make('driver')
                    ->options(array_combine($drivers, array_map(ucfirst(...), $drivers)))
                    ->default('suite')
                    ->required()
                    ->native(false),
                TextInput::make('base_url')
                    ->label('Base URL')
                    ->helperText('The other app\'s root URL, e.g. https://registration.example.com')
                    ->url()
                    ->required(),
                TextInput::make('token')
                    ->label('API token')
                    ->helperText('Issued by the other app\'s API Clients page. Leave blank to keep the current token when editing.')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state)),
            ]);
    }
}
