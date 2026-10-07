<?php

use Filament\Notifications\DatabaseNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Jma\Connect\Consumer\Jobs\SyncMappingJob;
use Jma\Connect\Consumer\Models\Connection;
use Jma\Connect\Consumer\Models\SyncMapping;
use Jma\Connect\Tests\Fixtures\StubAlertRecipients;
use Jma\Connect\Tests\Fixtures\User;

beforeEach(function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Notification::fake();
});

function notifyingMapping(): SyncMapping
{
    return Connection::create(['name' => 'LLS', 'driver' => 'suite', 'base_url' => 'https://source.test', 'token' => 'secret'])
        ->mappings()->create(['remote_event_id' => '7', 'remote_event_name' => 'Spring Gala', 'local_event_id' => 99]);
}

function sourceReturnsOneAttendee(): void
{
    Http::fake(['source.test/api/v1/events/7/attendees*' => Http::response([
        'data' => [['id' => 1, 'first_name' => 'Ada', 'last_name' => 'Lovelace']],
        'links' => ['next' => null],
    ])]);
}

it('tells whoever clicked Sync now how their run went', function () {
    sourceReturnsOneAttendee();
    $clicker = User::create(['name' => 'Joel']);

    SyncMappingJob::dispatchSync(notifyingMapping(), $clicker);

    Notification::assertSentTo($clicker, DatabaseNotification::class, fn (DatabaseNotification $notification) => $notification->data['title'] === 'Synced Spring Gala'
        && $notification->data['status'] === 'success');
});

it('sends nothing for a scheduled run that succeeds', function () {
    sourceReturnsOneAttendee();
    config()->set('connect.consumer.alert_recipients', StubAlertRecipients::class);
    User::create(['name' => 'Admin One']);

    SyncMappingJob::dispatchSync(notifyingMapping());

    Notification::assertNothingSent();
});

it('alerts the recipients and the clicker once when a run fails for good', function () {
    Http::fake(['source.test/*' => Http::response(['message' => 'Server error'], 500)]);
    config()->set('connect.consumer.alert_recipients', StubAlertRecipients::class);
    $admin = User::create(['name' => 'Admin One']);
    $clickingAdmin = User::create(['name' => 'Admin Two']);
    $bystander = User::create(['name' => 'Client owner']);

    rescue(fn () => SyncMappingJob::dispatchSync(notifyingMapping(), $clickingAdmin), report: false);

    Notification::assertSentTo($admin, DatabaseNotification::class, fn (DatabaseNotification $notification) => $notification->data['title'] === 'Sync failed: Spring Gala');
    Notification::assertSentToTimes($clickingAdmin, DatabaseNotification::class, 1);
    Notification::assertNotSentTo($bystander, DatabaseNotification::class);
});

it('only alerts the clicker about a failure when the host names no recipients', function () {
    Http::fake(['source.test/*' => Http::response(['message' => 'Server error'], 500)]);
    $clicker = User::create(['name' => 'Joel']);
    $admin = User::create(['name' => 'Admin One']);

    rescue(fn () => SyncMappingJob::dispatchSync(notifyingMapping(), $clicker), report: false);

    Notification::assertSentTo($clicker, DatabaseNotification::class);
    Notification::assertNotSentTo($admin, DatabaseNotification::class);
});
