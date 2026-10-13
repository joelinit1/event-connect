<?php

namespace Jma\Connect\Consumer\Filament\Resources\Connections\RelationManagers;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;
use Jma\Connect\Connect;
use Jma\Connect\Consumer\Jobs\SyncMappingJob;
use Jma\Connect\Consumer\Models\Connection;
use Jma\Connect\Consumer\Models\SyncMapping;
use Jma\Connect\Consumer\Sync\MappingPreview;
use Jma\Connect\Consumer\Sync\RetryHeldRows;
use Jma\Connect\Data\EventData;
use Jma\Connect\Filament\Components\FieldMapRepeater;
use Jma\Connect\Mapping\Suggest;
use Jma\Connect\Support\LocalTables;

class SyncMappingsRelationManager extends RelationManager
{
    protected static string $relationship = 'mappings';

    protected static ?string $title = 'Event mappings';

    public const QUEUED_BODY = 'The result will appear in your notifications when it finishes.';

    /** Columns that usually hold another system's ID, tried in order. */
    private const KEY_COLUMN_GUESSES = ['remote_id', 'external_id', 'external_ref', 'source_id', 'connect_id', 'remote_ref', 'sync_id'];

    public function form(Schema $schema): Schema
    {
        $columns = fn (Get $get) => LocalTables::columnOptions($get('target_table'));

        return $schema
            ->columns(1)
            ->components([
                Section::make('Event')
                    ->columns(3)
                    ->schema([
                        Select::make('remote_event_id')
                            ->label('Remote event')
                            ->options(fn () => $this->remoteEventOptions())
                            ->searchable()
                            ->live()
                            ->required(),
                        $this->localEventField(),
                        Toggle::make('enabled')
                            ->inline(false)
                            ->default(true),
                    ]),
                Section::make('Destination')
                    ->description('The local table these attendees are written into.')
                    ->visible(fn () => Connect::usesBuiltInSink())
                    ->columns(2)
                    ->schema([
                        Select::make('target_table')
                            ->label('Table')
                            ->options(fn () => LocalTables::options())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                $columns = LocalTables::columns($state);
                                $set('target_key_column', collect(self::KEY_COLUMN_GUESSES)->first(fn ($c) => in_array($c, $columns, true)));
                                $set('target_event_column', collect(['event_id'])->first(fn ($c) => in_array($c, $columns, true)));
                            }),
                        Select::make('target_key_column')
                            ->label('Remote ID column')
                            ->helperText('Stores the other app\'s attendee ID, so each attendee is matched to the same row every sync. Add a column (e.g. external_id) if the table has none.')
                            ->options($columns)
                            ->required(),
                        Select::make('target_event_column')
                            ->label('Event column (optional)')
                            ->helperText('Only if this table holds more than one event\'s attendees: set to the local event on write, and used to match rows.')
                            ->options($columns),
                        Select::make('on_delete')
                            ->label('When the source deletes an attendee')
                            ->options(SyncMapping::ON_DELETE)
                            ->default('delete')
                            ->required(),
                    ]),
                Section::make('Fields')
                    ->description('Which column each incoming field is written to. Fields not mapped are left alone locally.')
                    ->visible(fn () => Connect::usesBuiltInSink())
                    ->schema([
                        FieldMapRepeater::make(
                            fromOptions: fn (Closure $field) => $this->remoteFields($field('remote_event_id')),
                            toOptions: fn (Closure $field) => LocalTables::columnOptions($field('target_table')),
                            toSuggestions: null,
                            suggest: fn (Closure $field) => Suggest::forConsumer(
                                $this->remoteFields($field('remote_event_id')),
                                LocalTables::columns($field('target_table')),
                                array_filter([$field('target_key_column'), $field('target_event_column')]),
                            ),
                            suggestLabel: 'Suggest from source',
                        ),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('openIssues')->with(['latestRun', 'latestSuccessfulRun']))
            ->columns([
                TextColumn::make('remote_event_name')
                    ->label('Remote event')
                    ->description(fn (SyncMapping $record) => "ID {$record->remote_event_id}"),
                TextColumn::make('localEvent.'.config('connect.consumer.event_label', 'name'))
                    ->label('Local event')
                    ->visible(fn () => filled(config('connect.consumer.event_model'))),
                TextColumn::make('target_table')
                    ->label('Destination')
                    ->visible(fn () => Connect::usesBuiltInSink())
                    ->placeholder('Not set')
                    ->description(fn (SyncMapping $record) => count($record->fieldMap()->rules()).' field(s) mapped'),
                IconColumn::make('enabled')
                    ->boolean(),
                TextColumn::make('health')
                    ->state(fn (SyncMapping $record) => $record->health())
                    ->badge()
                    ->tooltip(fn (SyncMapping $record) => $record->health()->getDescription()),
                TextColumn::make('open_issues_count')
                    ->label('Held rows')
                    ->badge()
                    ->color(fn (int $state) => $state === 0 ? 'gray' : 'danger'),
                TextColumn::make('last_synced_at')
                    ->label('Last successful sync')
                    ->state(fn (SyncMapping $record) => $record->lastSyncedAt())
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('Never'),
                TextColumn::make('latestRun.status')
                    ->label('Last run')
                    ->badge()
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->modalWidth(Width::SevenExtraLarge)
                    ->mutateDataUsing(fn (array $data) => $this->withRemoteEventName($data)),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label('Preview')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->modalHeading(fn (SyncMapping $record) => "Preview: {$record->remote_event_name}")
                    ->modalDescription('The first attendees from the source, as they would be written with the current settings. Nothing is saved.')
                    ->modalWidth(Width::SevenExtraLarge)
                    ->modalContent(fn (SyncMapping $record) => view('connect::preview', MappingPreview::preview($record)))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Action::make('syncNow')
                    ->label('Sync now')
                    ->icon(Heroicon::ArrowPath)
                    ->tooltip('Fetch attendees changed since the last sync, same as the hourly run.')
                    ->action(function (SyncMapping $record): void {
                        SyncMappingJob::dispatch($record, auth()->user());

                        Notification::make()->title('Sync queued')->body(self::QUEUED_BODY)->success()->send();
                    }),
                ActionGroup::make([
                    Action::make('fieldReport')
                        ->label('Field report')
                        ->icon(Heroicon::OutlinedChartBar)
                        ->modalHeading(fn (SyncMapping $record) => "Field report: {$record->remote_event_name}")
                        ->modalDescription('How often each field arrived with a value in the latest sync, and where it went.')
                        ->modalWidth(Width::FiveExtraLarge)
                        ->modalContent(fn (SyncMapping $record) => view('connect::preview', MappingPreview::report($record)))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close'),
                    Action::make('retryHeld')
                        ->label('Retry held rows')
                        ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
                        ->visible(fn (SyncMapping $record) => $record->openIssueCount() > 0)
                        ->requiresConfirmation()
                        ->modalDescription('Writes the held rows again with the mapping as it is now. Rows that still fail stay held.')
                        ->action(fn (SyncMapping $record) => self::notifyRetry(app(RetryHeldRows::class)->retry($record))),
                    Action::make('fullResync')
                        ->label('Full resync')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->requiresConfirmation()
                        ->modalDescription('Forgets the cursor and re-reads every attendee for this event. Nothing is deleted.')
                        ->action(function (SyncMapping $record): void {
                            $record->update(['cursor' => null]);

                            SyncMappingJob::dispatch($record, auth()->user());

                            Notification::make()->title('Full resync queued')->body(self::QUEUED_BODY)->success()->send();
                        }),
                    EditAction::make()
                        ->modalWidth(Width::SevenExtraLarge)
                        ->mutateDataUsing(fn (array $data) => $this->withRemoteEventName($data)),
                    DeleteAction::make(),
                ]),
            ]);
    }

    /**
     * @param  array{fixed: int, failed: int}  $result
     */
    public static function notifyRetry(array $result): void
    {
        Notification::make()
            ->title("{$result['fixed']} row(s) written, {$result['failed']} still held")
            ->body($result['failed'] > 0 ? 'Open Held rows to see why the rest still fail.' : null)
            ->{$result['failed'] > 0 ? 'warning' : 'success'}()
            ->send();
    }

    private function localEventField(): Select|TextInput
    {
        if (blank(config('connect.consumer.event_model'))) {
            return TextInput::make('local_event_id')
                ->label('Local event ID (optional)')
                ->helperText('Written to the event column, if you set one below.')
                ->integer();
        }

        return Select::make('local_event_id')
            ->label('Local event')
            ->options(fn () => $this->localEventOptions())
            ->searchable();
    }

    /**
     * Field paths the source sends for an event, for suggestions and the
     * "From" dropdown. Empty (not an error) when the source can't be reached.
     *
     * @return list<string>
     */
    private function remoteFields(?string $remoteEventId): array
    {
        if (blank($remoteEventId)) {
            return [];
        }

        /** @var Connection $connection */
        $connection = $this->getOwnerRecord();
        $mapping = $connection->mappings()->firstWhere('remote_event_id', $remoteEventId)
            ?? new SyncMapping(['connection_id' => $connection->id, 'remote_event_id' => $remoteEventId]);
        $mapping->setRelation('connection', $connection);

        return rescue(fn () => MappingPreview::remoteFields($mapping), [], report: false);
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
