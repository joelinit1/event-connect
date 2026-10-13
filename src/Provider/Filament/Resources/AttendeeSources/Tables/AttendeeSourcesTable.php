<?php

namespace Jma\Connect\Provider\Filament\Resources\AttendeeSources\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Jma\Connect\Provider\Filament\Resources\AttendeeSources\AttendeeSourceResource;
use Jma\Connect\Provider\Models\AttendeeSource;

class AttendeeSourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Event ID')
                    ->tooltip('What API clients see as this event\'s ID.'),
                TextColumn::make('tenant.'.config('connect.provider.tenant_label', 'name'))
                    ->label('Client')
                    ->placeholder('—')
                    ->visible(fn () => filled(config('connect.provider.tenant_model'))),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('table_name')
                    ->label('Table')
                    ->description(fn (AttendeeSource $record) => filled($record->filter_column)
                        ? "where {$record->filter_column} = {$record->filter_value}"
                        : null),
                TextColumn::make('rules')
                    ->label('Mapped fields')
                    ->state(fn (AttendeeSource $record) => count($record->fieldMap()->rules()))
                    ->badge()
                    ->color(fn (int $state) => $state === 0 ? 'warning' : 'gray'),
                TextColumn::make('status')
                    ->state(fn (AttendeeSource $record) => $record->configurationProblems() === [] ? 'OK' : 'Misconfigured')
                    ->badge()
                    ->color(fn (string $state) => $state === 'OK' ? 'success' : 'danger')
                    ->tooltip(fn (AttendeeSource $record) => implode("\n", $record->configurationProblems()) ?: null),
                IconColumn::make('enabled')
                    ->boolean(),
            ])
            ->recordActions([
                AttendeeSourceResource::previewAction(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
