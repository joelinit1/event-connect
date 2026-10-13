<?php

use Jma\Connect\Consumer\Sources\SuiteSource;

/*
 * Everything here can be set from .env, so installing needs no code beyond
 * registering ConnectPlugin on a Filament panel.
 *
 * Every Filament screen this package registers is gated by the
 * `connect.manage` Gate ability. Hosts may define it in a service provider:
 *
 *     Gate::define('connect.manage', fn (User $user) => $user->is_super_admin);
 *
 * If they don't, users whose email is listed in CONNECT_ADMIN_EMAILS
 * (comma-separated) are allowed and everyone else is denied.
 */
return [

    'admin_emails' => env('CONNECT_ADMIN_EMAILS', ''),

    /*
     * The Filament panel (by ID) the screens are added to. Null means the
     * super admin's panel: the default one, unless that's a client panel
     * (with tenancy) and the app has one without, e.g. /platform. Ignored
     * if the host registers ConnectPlugin on a panel itself.
     */
    'panel' => env('CONNECT_PANEL'),

    /*
     * Provider mode: expose this app's events and attendees at
     * /api/v1/events and /api/v1/events/{id}/attendees, authenticated by
     * Sanctum tokens issued to ApiClient records from the Filament panel.
     */
    'provider' => [
        'enabled' => (bool) env('CONNECT_PROVIDER_ENABLED', false),

        // Class implementing Jma\Connect\Contracts\AttendeeProvider. Null
        // uses the built-in TableAttendeeProvider, which serves whatever is
        // set up on the Attendee Sources screen.
        'class' => null,

        // Optional: scope API clients to a tenant/client model.
        'tenant_model' => null,
        'tenant_label' => 'name',
    ],

    /*
     * Consumer mode: pull events and attendees from other apps into this
     * one via Connections → SyncMappings → queued SyncMappingJob runs.
     */
    'consumer' => [
        'enabled' => (bool) env('CONNECT_CONSUMER_ENABLED', false),

        // Class implementing Jma\Connect\Contracts\AttendeeSink. Null uses
        // the built-in TableAttendeeSink, which writes into the table and
        // field map set on each event mapping.
        'sink' => null,

        // Cron expression for syncing every enabled mapping. Null leaves
        // scheduling to the host (`connect:sync`). Needs the host's
        // scheduler (`schedule:run`); runs happen in the scheduler's own
        // process, so no queue worker is needed.
        'schedule' => env('CONNECT_SCHEDULE', '0 * * * *'),

        // Optional: scope connections to a tenant/client model. When set,
        // the event model is expected to carry a column (event_tenant_key)
        // holding that tenant's id.
        'tenant_model' => null,
        'tenant_label' => 'name',
        'event_tenant_key' => 'tenant_id',

        // Optional: the local model a remote event is mapped onto, offered
        // as a dropdown on the event mapping screen. Without it, the local
        // event is a plain ID, which only matters if the destination table
        // holds several events' attendees in one table.
        'event_model' => null,
        'event_label' => 'name',

        'queue' => env('CONNECT_QUEUE', 'default'),

        // Re-read this many minutes before the last cursor so records
        // changed while a long run was in flight are never missed.
        'cursor_overlap_minutes' => 5,

        // A mapping shows as Overdue once its last successful run is older
        // than this. The schedule runs hourly, so the default allows one
        // missed run plus slack before flagging it.
        'stale_after_minutes' => 120,

        // Class implementing Jma\Connect\Contracts\SyncAlertRecipients: who
        // gets a Filament database notification when a sync fails for good
        // (after its retries). Null means nobody beyond whoever clicked
        // "Sync now", who is always told how their own run went.
        'alert_recipients' => null,

        // Model classes to consider, beyond those found in app/Models, when
        // looking for the app's own model for a destination table. Rows are
        // written through that model so its hooks and casts apply.
        'models' => [],

        // driver name => class implementing Jma\Connect\Contracts\Source.
        'drivers' => [
            'suite' => SuiteSource::class,
        ],
    ],

];
