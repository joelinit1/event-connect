<?php

namespace Jma\Connect\Consumer\Notifications;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Jma\Connect\Consumer\Filament\Resources\Connections\ConnectionResource;
use Jma\Connect\Consumer\Models\SyncRun;
use Jma\Connect\Enums\SyncRunStatus;

/**
 * The bell entry for one finished run. Built as a Filament notification so
 * any panel with databaseNotifications() shows it without host code.
 */
class SyncRunNotification
{
    public static function make(SyncRun $run): Notification
    {
        $mapping = $run->mapping;
        $event = $mapping->remote_event_name ?? "event {$mapping->remote_event_id}";
        $errorCount = count($run->errors ?? []);

        $notification = match ($run->status) {
            SyncRunStatus::Succeeded => Notification::make()
                ->success()
                ->title("Synced {$event}")
                ->body(self::counts($run)),
            SyncRunStatus::Partial => Notification::make()
                ->warning()
                ->title("Synced {$event} with {$errorCount} row error(s)")
                ->body(self::counts($run)),
            default => Notification::make()
                ->danger()
                ->title("Sync failed: {$event}")
                ->body(str((string) last($run->errors ?? []))->limit(200)->toString() ?: 'The job failed.'),
        };

        $url = ConnectionResource::editUrlFor($mapping->connection);

        return $url === null ? $notification : $notification->actions([
            Action::make('view')
                ->label('View sync history')
                ->url($url)
                ->markAsRead(),
        ]);
    }

    private static function counts(SyncRun $run): string
    {
        return "{$run->created} created, {$run->updated} updated, {$run->deleted} deleted, {$run->skipped} skipped.";
    }
}
