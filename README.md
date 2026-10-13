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

In each app, provider or consumer. No code changes:

1. Locally, in the app: tell Composer where the package lives (it's a public
   GitHub repo, not on Packagist, and needs no token), then require it:

   ```bash
   composer config repositories.event-connect vcs https://github.com/joelinit1/event-connect.git
   composer require joelinit1/event-connect:^0.2
   ```

   Commit `composer.json` and `composer.lock` and deploy. The deploy's
   `php artisan migrate --force` adds the package's tables.
2. Set `.env`:

   ```dotenv
   CONNECT_PROVIDER_ENABLED=true        # this app shares its attendees
   CONNECT_CONSUMER_ENABLED=true        # this app pulls attendees in
   CONNECT_ADMIN_EMAILS=you@example.com # who can see the screens (comma-separated)
   ```

3. Consumers only: the Laravel scheduler (`schedule:run` every minute) must
   be running. The package syncs every enabled mapping hourly by itself,
   inside the scheduler, so no queue worker is needed. Change the timing
   with `CONNECT_SCHEDULE` (a cron expression), or set it empty to schedule
   `connect:sync` yourself.

The screens appear under **Platform Admin** in the super admin's panel:

| The app's panels | Where the screens go |
| --- | --- |
| One panel | That panel |
| A default admin panel, plus others (host, gateway, client portal) | The default admin panel |
| A default **client** panel with tenancy, plus a panel without (e.g. `/manage` and `/platform`) | The panel without tenancy (`platform` or `admin` first) |
| One panel shared with clients through tenancy | That panel, inside any client (`/admin/<client>/…`) |

To choose a panel yourself, set `CONNECT_PANEL` to its ID. An app that
registers `ConnectPlugin` on a panel itself keeps that, and nothing is
added. Only users listed in `CONNECT_ADMIN_EMAILS` see the screens; anyone
else gets no menu entry, and a 403 if they open the address directly.

Sanctum's `personal_access_tokens` table, which API client tokens need, is
created by the migration if the app doesn't have it.

Optional: `->databaseNotifications()` on the panel shows sync alerts in the
bell. **Sync now** reports its result on screen either way.

A host that defines the `connect.manage` Gate itself overrides
`CONNECT_ADMIN_EMAILS`. A host with its own `AttendeeProvider` or
`AttendeeSink` class can still name it in a published `config/connect.php`,
and the table settings then go away from the screens.

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
   the local table. If the table has an event column (e.g. `event_id`), it's
   filled in. Then pick the **local event** from the dropdown, which lists the
   rows that column points at by name.
3. **Remote ID column** is optional. Leave it empty and the package remembers
   which local row each attendee became, so the table needs no new column.
   Set it only if the table already has one meant for another system's ID
   (e.g. `external_ref`).
4. Press **Suggest from source**, check the rules, then save.
5. **Preview** really writes the first attendees to the table and then undoes
   it, so each row's result (create, update, or held and why) is what a sync
   will actually do, including the table's required columns and the app's own
   rules. Nothing is kept, and no emails, notifications or jobs go out. Then
   use **Sync now**, which runs straight away and shows the result.

Rows are written through the app's own Eloquent model for the table when it
has one (found in `app/Models`). So anything the app does when it creates a
row itself, like generating a check-in token, a UUID or a QR code, filling
defaults or soft deleting, happens for synced rows too. A table with no model
is written directly.

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
blanked. Rows are matched on the remote ID column (plus the event column), or
on the row the package recorded creating. Never on email.

### Behind Cloudflare

The consumer calls the provider's `/api/v1` from its server. If the
provider's domain is proxied by Cloudflare with **Bot Fight Mode** on,
Cloudflare challenges that call and it never reaches the provider. **Test**
on the connection then says "Cloudflare blocked this request". On the free
plan, Bot Fight Mode can't be skipped for one path, so it has to be off for
that zone.

## Seeing and fixing mis-mappings

- **Preview** (on both sides): real rows through the current mapping, before
  saving or syncing.
- **Held rows** (a tab on each connection): every attendee a sync couldn't
  write, with the reason in plain words (e.g. "Column 'event_id' needs a
  value, but nothing writes one") and exactly what the source sent. Fix the mapping,
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

- `composer require joelinit1/event-connect:^0.2` (`^0.1` doesn't accept
  0.2), then migrate. The repo is public now, so the Composer credential
  (GitHub token) on the app's Forge site isn't needed any more; switch the
  app's `repositories` URL to `https://github.com/joelinit1/event-connect.git`. That adds `connect_attendee_sources`,
  `connect_sync_issues` and `connect_row_links`, adds destination and
  field-map columns to `connect_sync_mappings` (making `local_event_id`
  nullable), and adds field counts to `connect_sync_runs`.
- Hosts with their own `AttendeeProvider`/`AttendeeSink` class keep working
  unchanged, because their published `config/connect.php` still names it. To
  move to the screens, set up the sources or mappings there, then delete
  `config/connect.php` (or set `class`/`sink` to `null` in it) and use the
  `.env` settings above. A published config from 0.1 also lacks the `.env`
  lookups, so its hard-coded `enabled` values win until it's removed.
- `connect:sync` is now scheduled by the package, and runs syncs itself
  rather than queuing them (`--queue` queues them as before). If the host
  schedules it too, remove that line or set `CONNECT_SCHEDULE=`. Duplicates
  are harmless.
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
prefix. While the package is on `0.x`, apps require `^0.2`, which accepts
`0.2.*` only. That means additive releases are `0.2.x`, and `0.3.0` is
reserved for breaking changes to the PHP API (contracts, config, migrations).
The wire format didn't change in 0.2, so a 0.2 app and a 0.1 app still sync.

## Development

```bash
composer test   # Pest: Unit, Feature, Screens (Filament) and Install suites
composer lint   # Pint
```

The suite also runs on MySQL:

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=event_connect_test DB_USERNAME=root DB_PASSWORD= composer test
```

`tests/Feature/EndToEndTest.php` and `tests/Feature/RealWorldTest.php` run a
provider and a consumer with different table layouts in one app, configured
only through what the screens save. RealWorldTest's tables are shaped like
real apps: a single full-name column, an events table, and a check-in table
whose model generates a required token.
