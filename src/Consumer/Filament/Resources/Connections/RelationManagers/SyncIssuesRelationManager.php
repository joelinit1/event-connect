<?php

namespace Jma\Connect\Consumer\Filament\Resources\Connections\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\HtmlString;
use Jma\Connect\Consumer\Models\SyncIssue;
use Jma\Connect\Consumer\Sync\RetryHeldRows;

/**
 * Attendees a sync couldn't write, with the reason and exactly what the
 * source sent. Fix the mapping (or the source), then retry: no resync.
 */
class SyncIssuesRelationManager extends RelationManager
{
    protected static string $relationship = 'issues';

    protected static ?string $title = 'Held rows';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('connect_sync_issues.last_seen_at', 'desc')
            ->emptyStateHeading('No held rows')
            ->emptyStateDescription('Every attendee the source sent has been written.')
            ->columns([
                TextColumn::make('mapping.remote_event_name')
                    ->label('Event'),
                TextColumn::make('remote_id')
                    ->label('Remote ID')
                    ->searchable(),
                TextColumn::make('attendee')
                    ->state(fn (SyncIssue $record) => trim(($record->payload['first_name'] ?? '').' '.($record->payload['last_name'] ?? '')) ?: '—'),
                TextColumn::make('reason')
                    ->wrap()
                    ->limit(160)
                    ->tooltip(fn (SyncIssue $record) => $record->reason),
                TextColumn::make('occurrences')
                    ->label('Seen')
                    ->suffix('×'),
                TextColumn::make('last_seen_at')
                    ->label('Last seen')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
                TextColumn::make('resolved_at')
                    ->label('Status')
                    ->state(fn (SyncIssue $record) => $record->isOpen() ? 'Held' : 'Resolved')
                    ->badge()
                    ->color(fn (string $state) => $state === 'Held' ? 'danger' : 'success'),
            ])
            ->filters([
                TernaryFilter::make('open')
                    ->label('Status')
                    ->placeholder('All')
                    ->trueLabel('Held')
                    ->falseLabel('Resolved')
                    ->default(true)
                    ->queries(
                        true: fn ($query) => $query->whereNull('connect_sync_issues.resolved_at'),
                        false: fn ($query) => $query->whereNotNull('connect_sync_issues.resolved_at'),
                    ),
            ])
            ->recordActions([
                Action::make('data')
                    ->label('Data')
                    ->icon(Heroicon::OutlinedCodeBracket)
                    ->color('gray')
                    ->modalHeading(fn (SyncIssue $record) => "What the source sent for {$record->remote_id}")
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->modalContent(fn (SyncIssue $record) => new HtmlString(
                        '<p style="margin-bottom:8px">'.e($record->reason).'</p><pre style="font-size:12px;white-space:pre-wrap">'
                        .e(json_encode($record->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)).'</pre>'
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Action::make('retry')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (SyncIssue $record) => $record->isOpen())
                    ->action(fn (SyncIssue $record) => self::retry(new EloquentCollection([$record]))),
                Action::make('dismiss')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->visible(fn (SyncIssue $record) => $record->isOpen())
                    ->requiresConfirmation()
                    ->modalDescription('Stops showing this row as held without writing it. It comes back if the source sends it again and it still fails.')
                    ->action(fn (SyncIssue $record) => $record->update(['resolved_at' => now()])),
            ])
            ->toolbarActions([
                BulkAction::make('retrySelected')
                    ->label('Retry selected')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->action(fn (EloquentCollection $records) => self::retry($records)),
                BulkAction::make('dismissSelected')
                    ->label('Dismiss selected')
                    ->icon(Heroicon::OutlinedXMark)
                    ->requiresConfirmation()
                    ->action(function (EloquentCollection $records): void {
                        SyncIssue::whereKey($records->modelKeys())->update(['resolved_at' => now()]);

                        Notification::make()->title('Dismissed '.$records->count().' row(s)')->success()->send();
                    }),
            ]);
    }

    /**
     * @param  EloquentCollection<int, SyncIssue>  $issues
     */
    private static function retry(EloquentCollection $issues): void
    {
        $result = ['fixed' => 0, 'failed' => 0];

        foreach ($issues->load('mapping.connection')->groupBy('sync_mapping_id') as $group) {
            $outcome = app(RetryHeldRows::class)->retry($group->first()->mapping, $group);
            $result['fixed'] += $outcome['fixed'];
            $result['failed'] += $outcome['failed'];
        }

        SyncMappingsRelationManager::notifyRetry($result);
    }
}
