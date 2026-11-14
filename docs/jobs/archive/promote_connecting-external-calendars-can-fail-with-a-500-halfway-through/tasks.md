# Tasks: connecting external calendars can fail with a 500 halfway through

id: promote
analyst: @analyst
date: 2026-11-14

## Context (verified in code)

- Native creation path: `CalendarService::create()` → `Dav\Services\Calendars::create()` → `AppCalendarsPDO::createCalendarCustom()` (`app/Dav/Backend/AppCalendarsPDO.php:479-492`). The uri comes from `Str::slug($dto->displayName)` at line 491 (the brief cites :431; that line number is out of date). `Calendars::create()` then reads the row back with `getByName()`, so the display name is still the lookup key. Display names are already unique per user (`CalendarService::create()` throws `CalendarAlreadyExistsException`).
- The unique index `calendarinstances_principaluri_uri (principaluri, uri)` covers all of a principal's instances, sharee instances included. Sabre gives those a UUID uri.
- **The DAV tables and `external_calendars` are on different connections.** DAV uses `DB::connection('pgsql')->getPdo()` (`app/Dav/Services/DavService.php:22`). `ExternalCalendar` is on the default connection (MariaDB; in tests, in-memory sqlite, with `pgsql` mapped to a separate sqlite file, see `tests/TestCase.php:40-47`). So step 2's cleanup branch applies: one transaction alone can't cover both.
- `AppContactsPDO.php:52` builds addressbook uris with the same `Str::slug` pattern. That is out of scope here; see Follow-ups.

## Task breakdown

TASK-1: In `AppCalendarsPDO::createCalendarCustom()`, generate a uri that is unique per principal. Use `Str::slug(displayName)`, fall back to a UUID (`Str::uuid()` or Sabre's `UUIDUtil`) when the slug is empty, and add a `-1`, `-2`, … suffix while a `calendarinstances` row with the same `(principaluri, uri)` exists.
  files: app/Dav/Backend/AppCalendarsPDO.php
  depends: none
  risk: medium. This is the shared create path for every native calendar (including `createDefaultCalendar`) on the `pgsql`/DAV layer. It changes no schema, routes or connection, but should be flagged per the hard rules. The uri is the DAV collection path clients see, so existing calendars must keep their uri (only new creations are affected).

TASK-2: Make `ExternalCalendarService::connect()` all-or-nothing. Do the loop's `ExternalCalendar::create` writes inside a `DB::transaction()` on the default connection. If any step throws, delete the DAV calendars this call already created (via `CalendarService::destroy()`), then rethrow. Run `markExternal` / cache forgets only for calendars that end up connected. Also update the method's docblock.
  files: app/Api/Calendars/Services/ExternalCalendarService.php
  depends: none (independent of TASK-1, but TASK-3's collision tests need TASK-1)
  risk: medium. Rollback across two connections is compensating, not atomic. A failure inside the cleanup itself must not hide the original exception. The developer should decide whether to also wrap the DAV writes in a `DB::connection('pgsql')->transaction()`; it works because DavService shares that PDO, but sabre's `createCalendar` must not open its own transaction (check this before relying on it).

TASK-3: Add Pest tests in the existing external-calendar test file. (a) Connect a remote "work" while a local "Work" exists: the request succeeds, and both calendars exist with distinct uris. (b) Connect two emoji-only names: both connect with non-empty, distinct uris. (c) Make the second calendar fail. After TASK-1 a uri collision can no longer cause this, so inject the failure, e.g. with a partial `CalendarService` double or a throwing `ExternalCalendar` `creating` hook. Then check that no `external_calendars` rows and no new `calendarinstances` rows remain.
  files: app/Api/Calendars/Tests/ExternalCalendarApiTest.php (or a new co-located test file per docs/TESTING.md)
  depends: TASK-1, TASK-2
  risk: low. Tests only. The failure injection in (c) is the fiddly part and must follow docs/TESTING.md conventions for doubles.

TASK-4: Add a unit/feature test for the native path. Creating calendars whose names slug identically (e.g. "Work" and "work!"), or that slug to empty (emoji-only), via `POST /api/v1/calendars` gives distinct uris and no 500.
  files: app/Api/Calendars/Tests/CalendarApiTest.php (or app/Dav/Tests)
  depends: TASK-1
  risk: low. Tests only.

## Unclear / for the developer to confirm

- Brief step 1 says "random/uuid uri". Either works. Pick whichever the codebase already uses (Sabre's `UUIDUtil::getUUID()` is used by sabre for sharee instances; `Str::uuid()` elsewhere) and stay consistent.
- Suffix format (`work-1` vs `work-2`) isn't specified. It only needs to be deterministic and unique.

## Follow-ups (not in scope)

- `AppContactsPDO.php:52` has the same slug-only uri for addressbooks (`addressbooks` also has a unique `(principaluri, uri)` in sabre's schema). Worth its own backlog entry; not touched here, per "do not refactor unrelated things".
- When the job is done, remove or close `docs/backlog/external-calendar-connect-uri-collision.md`, since it is the source of this brief.
