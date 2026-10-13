# event-connect

Moves events and attendees between Laravel apps in the JMA event suite.

- **Provider mode** exposes a read-only JSON API at `/api/v1/events` and
  `/api/v1/events/{id}/attendees`, authenticated by Sanctum tokens issued to
  `ApiClient` records.
- **Consumer mode** pulls attendees from a provider on a schedule and writes
  them into a local table.

Every event can keep its attendees in a differently shaped table. Which table
to use and how its columns map is set per event on the admin screens, not in
code.

## Install

In each app, provider or consumer:

1. `composer require joelinit1/event-connect`, then `php artisan migrate`.
2. Register the plugin on a Filament panel that has database notifications
   (`->plugin(\Jma\Connect\ConnectPlugin::make())->databaseNotifications()`).
   This is the only code change.
3. Set `.env`:

   ```dotenv
   CONNECT_PROVIDER_ENABLED=true        # this app shares its attendees
   CONNECT_CONSUMER_ENABLED=true        # this app pulls attendees in
   CONNECT_ADMIN_EMAILS=you@example.com # who can see the screens (comma-separated)
   ```

4. Consumers only: the Laravel scheduler (`schedule:run` every minute) and a
   queue worker must be running. The package schedules `connect:sync` hourly
   by itself. Change that with `CONNECT_SCHEDULE` (a cron expression), or
   set it empty to schedule it yourself.

A host that defines the `connect.manage` Gate itself overrides
`CONNECT_ADMIN_EMAILS`. Leave the provider `class` and consumer `sink` config
unset to use the built-in table-driven versions described below. A host with
its own `AttendeeProvider` or `AttendeeSink` class can still set them, and
the table settings then go away from the screens.

## Setting up a sync (no code)

**On the provider** (the app that has the attendees):

1. **Attendee Sources → New.** Name the event, then pick its table. The ID,
   last-updated and soft-delete columns are filled in when the table has the
   usual ones. Set an **event column** and value only if one table holds
   several events' attendees.
2. Press **Suggest from table** and check the rules. Map at least
   `first_name`.
3. **Preview** shows the first rows exactly as they'll be sent, plus anything
   that needs fixing, e.g. a missing `first_name` or a column nothing reads.
4. **API Clients → New** issues a token. Copy it.

**On the consumer** (the app that receives them):

1. **API Connections → New.** Enter the provider's URL and the token, then
   press **Test**.
2. On the connection, open **Event mappings → New**. Pick the remote event and
   the local destination table. Choose the **remote ID column**: a column
   that stores the provider's attendee ID, so each attendee lands on the same
   row every time. Add one, e.g. `external_id`, if the table has none.
3. Press **Suggest from source**, check the rules, then save.
4. **Preview** shows what the first remote attendees would become locally
   (create, update or held, and why) before anything is written. Then use
   **Sync now**.

### Field maps

Each rule reads **From**, optionally applies a **Transform**, and writes
**To**:

| From | Reads |
| --- | --- |
| `email_address` | a column, or a wire field on the consumer side |
| `rsvp.dietary` | a key inside a JSON column, or `extra.dietary` on the wire |
| `rsvp.*` | every key in a JSON column (provider: map **To** `extra`) |
| `{first_name} {last_name}` | a template joining several fields |

Transforms split and join names (first word, everything after it, last word…),
change case, and parse dates, numbers and yes/no values. **Default** fills
empty values.

On the wire, the named fields are `first_name`, `last_name`, `email`,
`company`, `job_title`, `table`, `seat`, `zone`, `booking_ref`, `category`,
`check_in_code` and `checked_in_at`. **Anything else goes under
`extra.<name>`**, and the consumer can map it to any column. A new field needs
no package release: send it as an extra.

A field the source doesn't send leaves the local column alone. It is never
blanked. Rows are matched on the remote ID (plus the event column, if set),
never on email.

## Seeing and fixing mis-mappings

- **Preview** (on both sides): real rows through the current mapping, before
  saving or syncing.
- **Held rows** (a tab on each connection): every attendee a sync couldn't
  write, with the reason and exactly what the source sent. Fix the mapping,
  then **Retry**: no resync is needed. A held row that later syncs cleanly
  resolves itself, and **Dismiss** clears one by hand.
- **Field report** (per event mapping): how many attendees in the latest run
  had a value for each field, and which column it went to. A field at 0%, or
  one marked "not mapped", is where a mis-mapping shows up even when no row
  failed.
- **Warnings** on each run, which also go to the alert recipients when they're
  new: a mapped field the source stopped sending, one that arrived empty in
  every row, or a field the source started sending that nothing maps.

## Sync health and notifications (consumer mode)

Each event mapping has a `health()` that is worked out when it's read, not
stored. That way a stopped scheduler or queue worker still shows up, even
though it writes nothing:

| Health | Meaning |
| --- | --- |
| Healthy | Last successful run finished within `stale_after_minutes` (default 120) |
| Needs attention | Syncing, but the latest run raised a field-map warning |
| Overdue | Enabled, but no successful run in that window (or ever) |
| Failing | The latest run failed or held rows, or rows are still held from earlier |
| Off | Mapping disabled |

A connection's health is the worst of its mappings. Both are shown on the API
Connections screens. Hosts can show them elsewhere: eager-load `latestRun` and
`latestSuccessfulRun` first.

Notifications go to Filament's database notifications (the bell), so the host
panel needs `->databaseNotifications()`:

- **Sync now / Full resync / Sync all:** whoever clicked gets the outcome.
- **A run that fails for good** (after its retries), **or one that raises a
  field-map warning the previous run didn't**: everyone returned by the host's
  `connect.consumer.alert_recipients` class (a `Contracts\SyncAlertRecipients`),
  plus whoever clicked, if anyone. Leave the setting null and scheduled
  problems alert nobody.

Other successful scheduled runs never notify anyone.

## Upgrading from 0.1.x

- Run `php artisan migrate`. That adds `connect_attendee_sources` and
  `connect_sync_issues`, adds destination and field-map columns to
  `connect_sync_mappings` (making `local_event_id` nullable), and adds field
  counts to `connect_sync_runs`.
- `connect:sync` is now scheduled by the package. If the host schedules it
  too, remove that line or set `CONNECT_SCHEDULE=`. Duplicates are harmless,
  because a mapping already queued isn't queued twice.
- Hosts with their own `AttendeeProvider`/`AttendeeSink` classes keep working
  unchanged. To move a host onto the table-driven versions, set up its
  sources or mappings on the screens, then remove the class from config.
- Timestamps in source tables are read in the app's timezone (`UTC` by
  default), the same as Eloquent.

## Versioning

Provider and consumer apps are upgraded separately, so at any time they may
run different versions of this package. Both sides have to keep agreeing on
the **wire format**:

- the JSON keys of an event and an attendee, and what each one means;
- the response envelope (`data`, `links.next`);
- the query parameters (`updated_since`, `per_page`).

The agreed format is written out in `tests/Fixtures/contract/v1/`, and
`tests/Feature/ContractTest.php` fails if the code stops matching it.

**Allowed in any release under `/api/v1`: adding a key.** Update the fixture
in the same change. This is safe in both directions: an older consumer ignores
a key it doesn't know, and a newer consumer reading an older provider sees the
key as not sent (`AttendeeData::has()` returns false), so the sink leaves that
local column alone.

**Needs `/api/v2`: anything else that changes the format.** That covers
renaming or removing a key, changing a key's type or meaning, and making a
field newly required. Serve `/api/v2` alongside `/api/v1` until every consumer
has moved over, and leave the `v1` fixture untouched.

Package version numbers are separate from the API version. Tags have no `v`
prefix. While the package is on `0.x`, consumers require `^0.1`, which accepts
`0.1.*` only. That means additive releases are `0.1.x`, and `0.2.0` is
reserved for breaking changes to the PHP API (contracts, config, migrations).

## Development

```bash
composer test   # Pest: Unit, Feature and Screens (Filament) suites
composer lint   # Pint
```

`tests/Feature/EndToEndTest.php` runs a provider and a consumer with
different table layouts in one app, configured only through what the screens
save.
