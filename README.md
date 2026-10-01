# event-connect

Moves events and attendees between Laravel apps in the JMA event suite.

- **Provider mode** exposes a read-only JSON API at `/api/v1/events` and
  `/api/v1/events/{id}/attendees`, authenticated by Sanctum tokens issued to
  `ApiClient` records. The host app implements `Contracts\AttendeeProvider`.
- **Consumer mode** pulls attendees from a provider on a schedule and hands
  each one to the host app's `Contracts\AttendeeSink`.

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
composer test   # Pest
composer lint   # Pint
```
