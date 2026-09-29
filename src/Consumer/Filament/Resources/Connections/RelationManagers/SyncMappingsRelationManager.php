<?php

namespace Jma\Connect\Consumer\Filament\Resources\Connections\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;
use Jma\Connect\Consumer\Jobs\SyncMappingJob;
use Jma\Connect\Consumer\Models\Connection;
use Jma\Connect\Consumer\Models\SyncMapping;
use Jma\Connect\Data\EventData;

class SyncMappingsRelationManager extends RelationManager
{
    protected static string $relationship = 'mappings';

    protected static ?string $title = 'Event mappings';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('remote_event_id')
                    ->label('Remote event')
                    ->options(fn () => $this->remoteEventOptions())
                    ->searchable()
                    ->required(),
                Select::make('local_event_id')
                    ->label('Local event')
                    ->options(fn () => $this->localEventOptions())
                    ->searchable()
                    ->required(),
                Toggle::make('enabled')
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('remote_event_name')
                    ->label('Remote event')
                    ->description(fn (SyncMapping $record) => "ID {$record->remote_event_id}"),
                TextColumn::make('localEvent.'.config('connect.consumer.event_label', 'name'))
                    ->label('Local event'),
                IconColumn::make('enabled')
                    ->boolean(),
                TextColumn::make('cursor')
                    ->label('Synced up to')
                    ->since()
                    ->placeholder('Never'),
                TextColumn::make('latestRun.status')
                    ->label('Last run')
                    ->badge()
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(fn (array $data) => $this->withRemoteEventName($data)),
            ])
            ->recordActions([
                Action::make('syncNow')
                    ->label('Sync now')
                    ->icon(Heroicon::ArrowPath)
                    ->action(function (SyncMapping $record): void {
                        SyncMappingJob::dispatch($record);

                        Notification::make()->title('Sync queued')->success()->send();
                    }),
                Action::make('fullResync')
                    ->label('Full resync')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->requiresConfirmation()
                    ->modalDescription('Forgets the cursor and re-reads every attendee for this event. Nothing is deleted.')
                    ->action(function (SyncMapping $record): void {
                        $record->update(['cursor' => null]);

                        SyncMappingJob::dispatch($record);

                        Notification::make()->title('Full resync queued')->success()->send();
                    }),
                EditAction::make()
                    ->mutateDataUsing(fn (array $data) => $this->withRemoteEventName($data)),
                DeleteAction::make(),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private function remoteEventOptions(): array
    {
        /** @var Connection $connection */
        $connection = $this->getOwnerRecord();

        return Cache::remember(
            "connect:remote-events:{$connection->id}",
            60,
            fn () => $connection->source()->events()
                ->mapWithKeys(fn (EventData $event) => [$event->id => $event->name])
                ->all(),
        );
    }

    /**
     * @return array<int, string>
     */
    private function localEventOptions(): array
    {
        /** @var Connection $connection */
        $connection = $this->getOwnerRecord();
        $label = config('connect.consumer.event_label', 'name');

        return config('connect.consumer.event_model')::query()
            ->when(
                filled(config('connect.consumer.tenant_model')) && $connection->tenant_id,
                fn ($query) => $query->where(config('connect.consumer.event_tenant_key', 'tenant_id'), $connection->tenant_id),
            )
            ->orderBy($label)
            ->pluck($label, 'id')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withRemoteEventName(array $data): array
    {
        $data['remote_event_name'] = $this->remoteEventOptions()[$data['remote_event_id']] ?? $data['remote_event_id'];

        return $data;
    }
}
