<?php

namespace Jma\Connect\Consumer\Filament\Resources\Connections\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Jma\Connect\Consumer\Models\SyncRun;

class SyncRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'runs';

    protected static ?string $title = 'Sync history';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('connect_sync_runs.id', 'desc')
            ->columns([
                TextColumn::make('mapping.remote_event_name')
                    ->label('Remote event'),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('created'),
                TextColumn::make('updated'),
                TextColumn::make('deleted'),
                TextColumn::make('skipped'),
                TextColumn::make('errors')
                    ->state(fn (SyncRun $record) => count($record->errors ?? []))
                    ->label('Errors')
                    ->badge()
                    ->color(fn (int $state) => $state === 0 ? 'gray' : 'danger')
                    ->tooltip(fn (SyncRun $record) => implode("\n", $record->errors ?? [])),
            ]);
    }
}
