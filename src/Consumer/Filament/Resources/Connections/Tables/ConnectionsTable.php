<?php

namespace Jma\Connect\Consumer\Filament\Resources\Connections\Tables;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Jma\Connect\Consumer\Jobs\SyncMappingJob;
use Jma\Connect\Consumer\Models\Connection;
use Throwable;

class ConnectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tenant.'.config('connect.consumer.tenant_label', 'name'))
                    ->label('Client')
                    ->placeholder('—')
                    ->visible(fn () => filled(config('connect.consumer.tenant_model'))),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('driver')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('base_url')
                    ->label('Base URL')
                    ->searchable(),
                TextColumn::make('mappings_count')
                    ->counts('mappings')
                    ->label('Event mappings')
                    ->badge()
                    ->formatStateUsing(fn (int $state) => $state === 0 ? 'No mappings' : (string) $state)
                    ->color(fn (int $state) => $state === 0 ? 'warning' : 'gray'),
            ])
            ->recordActions([
                Action::make('testConnection')
                    ->label('Test')
                    ->icon(Heroicon::Bolt)
                    ->action(function (Connection $record): void {
                        try {
                            $record->source()->test();

                            Notification::make()->title('Connection OK')->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title('Connection failed')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('syncAll')
                    ->label('Sync all')
                    ->icon(Heroicon::ArrowPath)
                    ->action(function (Connection $record): void {
                        $mappings = $record->mappings()->where('enabled', true)->get();

                        $mappings->each(fn ($mapping) => SyncMappingJob::dispatch($mapping));

                        Notification::make()
                            ->title($mappings->isEmpty() ? 'Nothing to sync — add an event mapping first' : "Queued {$mappings->count()} sync run(s)")
                            ->{$mappings->isEmpty() ? 'warning' : 'success'}()
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
