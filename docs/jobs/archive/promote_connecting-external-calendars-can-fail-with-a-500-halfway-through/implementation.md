## Summary

New DAV calendars now get a uri that is unique per principal. Before, the uri was the bare slug of the display name, so names that slug alike or to nothing hit the `(principaluri, uri)` index and returned a 500. Connecting external calendars is now all-or-nothing. `composer test` and `composer analyse` both exit 0.

## Changes

TASK-1: `app/Dav/Backend/AppCalendarsPDO.php`. `createCalendarCustom()` uses a new private `uniqueUri()`, which takes `Str::slug(displayName)`, then `-1`, `-2`, … while a `calendarinstances` row with that `(principaluri, uri)` exists. An empty slug gets `Str::uuid()`, matching the rest of `app/`. This is the shared path for every native calendar on the `pgsql`/DAV layer, flagged per the hard rules: no schema, connection or route change, and existing calendars keep their uri. Only new creations are affected.

TASK-2: `app/Api/Calendars/Services/ExternalCalendarService.php`. `connect()` runs the loop inside `DB::transaction()` on the default connection, so the `external_calendars` rows roll back. The DAV tables live on `pgsql`, so on failure the DAV calendars this call created are removed with `CalendarService::destroy()` (compensating, not atomic), and then the original exception is rethrown. A failed cleanup is logged and does not hide the original exception. `markExternal` only runs once everything succeeded. I did not add a `pgsql` transaction: sabre's `createCalendar` doesn't open one, but the cleanup through `destroy()` already covers the case. The docblock is updated.

TASK-3: `app/Api/Calendars/Tests/ExternalCalendarApiTest.php`. Three tests: "work" next to a local "Work", two emoji-only names, and a forced failure on the second link (a throwing `ExternalCalendar::creating` hook) that leaves no links and no new `calendarinstances`. `tests/Support/FakeCalDavServer.php` gains `$siblings` so discovery can list more than one collection. I checked that all three tests fail with the pre-fix code.

TASK-4: `app/Api/Calendars/Tests/CalendarApiTest.php`. `POST /api/v1/calendars` with "Work", "work!", "Work?", "🎉" and "🎈" gives 201 every time and five distinct uris (`work`, `work-1`, `work-2`, plus two UUIDs).

Extra:
- `app/Dav/Tests/DavImportTest.php` ("logs a calendar it has to skip") made the import fail through exactly this slug collision. Now that the collision is gone, it forces the failure with a test-only SQLite trigger on `calendarinstances`. The trigger is dropped in `beforeApplicationDestroyed`.
- Removed `docs/backlog/external-calendar-connect-uri-collision.md`, since it was promoted to this job.

## Known issues / follow-ups

- Behavior change: the unique index used to stop a second DAV calendar with the same display name by accident. `Dav\Services\Calendars::create()` itself doesn't check names. `CalendarService::create()` and the import's name de-dup in `selectCalendars()` still guard the normal paths. But if a same-named calendar appears between the import's select and import steps, the import now creates a duplicate-named calendar instead of skipping it. A name check in `CalendarImport::importCalendars()` would close that gap.
- `AppContactsPDO.php:52` builds addressbook uris with the same slug-only pattern (and `addressbooks` has the same unique index). This needs its own backlog entry.
- `App\Api\Export\Tests\ExportTest` (Todo/FinanceWealth csv) failed once in a full run, then passed alone and in a full rerun. It looks flaky and is unrelated to this job.
- `.env.testing` shows as modified but git can't hash it ("unsupported file type", a mount in this session). I left it untouched and uncommitted.
