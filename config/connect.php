<?php

use Jma\Connect\Consumer\Sources\SuiteSource;

/*
 * Every Filament screen this package registers is gated by the
 * `connect.manage` Gate ability. Hosts define it in a service provider:
 *
 *     Gate::define('connect.manage', fn (User $user) => $user->is_super_admin);
 *
 * Undefined means denied. Keep authorization out of this file so it stays
 * cacheable by `config:cache`.
 */
return [

    /*
     * Provider mode: expose this app's events and attendees at
     * /api/v1/events and /api/v1/events/{id}/attendees, authenticated by
     * Sanctum tokens issued to ApiClient records from the Filament panel.
     */
    'provider' => [
        'enabled' => false,

        // Class implementing Jma\Connect\Contracts\AttendeeProvider.
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
        'enabled' => false,

        // Class implementing Jma\Connect\Contracts\AttendeeSink.
        'sink' => null,

        // Optional: scope connections to a tenant/client model. When set,
        // the event model is expected to carry a column (event_tenant_key)
        // holding that tenant's id.
        'tenant_model' => null,
        'tenant_label' => 'name',
        'event_tenant_key' => 'tenant_id',

        // The local model a remote event is mapped onto.
        'event_model' => null,
        'event_label' => 'name',

        'queue' => 'default',

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

        // driver name => class implementing Jma\Connect\Contracts\Source.
        'drivers' => [
            'suite' => SuiteSource::class,
        ],
    ],

];
