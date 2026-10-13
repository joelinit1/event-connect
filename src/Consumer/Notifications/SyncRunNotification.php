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

        $warnings = $run->warnings ?? [];

        $notification = match (true) {
            $run->status === SyncRunStatus::Succeeded && $warnings !== [] => Notification::make()
                ->warning()
                ->title("Synced {$event}: check the field mapping")
                ->body(self::counts($run).' '.str($warnings[0])->limit(200).(count($warnings) > 1 ? ' (+'.(count($warnings) - 1).' more)' : '')),
            $run->status === SyncRunStatus::Succeeded => Notification::make()
                ->success()
                ->title("Synced {$event}")
                ->body(self::counts($run)),
            $run->status === SyncRunStatus::Partial => Notification::make()
                ->warning()
                ->title("Synced {$event} with {$errorCount} held row(s)")
                ->body(self::counts($run).' Held rows can be retried once the mapping is fixed.'),
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
