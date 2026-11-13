# Tasks: invalidate sharees' calendar caches when a shared calendar changes

id: extend
analyst: analyst
date: 2026-11-13

<!-- Produced by @analyst from brief.md. -->

## Notes for the developer (read first)

- **DAV schema change, already approved.** The brief is the owner's sign-off
  for the `solyto_shared` column and the `calendarinstances (calendarid)`
  index on `pgsql` (hard rule / STANDARDS 12.6). Don't run `php artisan
  migrate` against a real database. `composer test` runs it on SQLite, which
  is fine. Put the full migration in `implementation.md` so the owner sees it
  before deploy.
- **`MigrationCompatibilityTest` will flag the migration.** It flags
  `DB::statement` and `DB::table()->update` inside `up()`
  (`tests/Unit/MigrationCompatibilityTest.php:131-139`). Add the migration to
  `MIGRATION_COMPATIBILITY_ALLOWLIST` with a reason. Suggested reason: additive
  DAV-only column plus index, the backfill touches only the new column, not
  exposed by any resource, approved in this job's brief (owner, 2026-11-13).
- **STANDARDS 5.5: no `pgsql` access from `app/Api`.** The brief has the new
  job query `calendarinstances` itself. Put that query (and the owner lookup)
  in `AppCalendarsPDO` behind `CalendarSharing`, and have the job call
  `DavService`. Then `grep -rn "connection('pgsql')" app/Api app/Shared` still
  lists only the three existing files.
- **API writes already read a fresh flag.** Every write endpoint resolves the
  calendar via `getOrFail()` → `getCalendarByIdCustom()`, which isn't cached
  (`CalendarController.php:292,365,376,446,515,611`). So the 24 h
  calendar-list cache can't make the write-time check stale. The owner-list
  forgets in TASK-6 only keep the cached list correct, as the brief asks.
- **Import doesn't need a dispatch** (brief step 5, last bullet). Checked
  `CalendarImport.php:76-151`: events go only into calendars the import just
  created via `Calendars::create()` (owned, `access = 1`, no sharees), found
  by name after `selectCalendars()` renamed around existing names. Leave
  `clearCache()` unchanged.
- `CalendarResource` lists its fields explicitly (`CalendarResource.php:29-41`),
  so the new DTO property isn't exposed and needs no OpenAPI change.

## Task breakdown

TASK-1: Add a driver-aware `pgsql` migration that adds `calendarinstances.solyto_shared` (boolean, not null, default false), creates index `calendarinstances_calendarid`, backfills owner rows (`access = 1`) of calendars that have an accepted sharee (`access > 1 AND share_invitestatus = 2`), and whose `down()` drops the index before the column; allowlist it in `MigrationCompatibilityTest`.
  files: database/migrations/2026_11_13_000001_add_solyto_shared_to_calendarinstances.php (new), tests/Unit/MigrationCompatibilityTest.php
  depends: none
  risk: medium — changes Sabre's table on the production DAV database; it's additive and Sabre names its columns, but it runs automatically on deploy and self-hosted installs.

TASK-2: Add a private `recomputeSharedFlag(int $calendarId)` in `AppCalendarsPDO` running the brief's idempotent `UPDATE … SET solyto_shared = EXISTS(…) WHERE calendarid = ? AND access = 1`, and call it from `acceptShare()` (look up the accepted row's `calendarid` in the same scoped query, return it as `?int` instead of `bool`), `unshareCalendar()`, `deleteCalendarCustom()` when the instance is a sharee's (`isShared`), and `deleteSharedInstancesCustom()` (collect the affected `calendarid`s before the delete, recompute each after).
  files: app/Dav/Backend/AppCalendarsPDO.php, app/Dav/Services/CalendarSharing.php (acceptInvite returns `?int` calendarId), app/Api/Calendars/Services/CalendarService.php (acceptInvite null check)
  depends: TASK-1
  risk: medium — a wrong scope in the recompute or a missed hook would leave the flag wrong; the recompute only writes the new column, so calendar data stays safe.

TASK-3: Add `public bool $solytoShared = false` to `CalendarDTO`, filled from the owner row in `getCalendarByIdCustom()` and `getCalendarByNameCustom()` (add `solyto_shared` to their column lists), and in `listCalendarsCustom()` through one extra query for the principal's `access = 1 AND solyto_shared` instance ids (`parent::getCalendarsForUser()` builds its own column list).
  files: app/Dav/DTOs/CalendarDTO.php, app/Dav/Backend/AppCalendarsPDO.php
  depends: TASK-1
  risk: low — adds a read-only field; `CalendarResource` doesn't expose it, and SQLite returns booleans as 0/1, so cast explicitly.

TASK-4: Add `AppCalendarsPDO`/`CalendarSharing` lookups for (a) the user ids of the owner plus accepted sharees of a calendar (`calendarid = ? AND (access = 1 OR share_invitestatus = 2)`, principal `principals/<email>` → `users.id`, uses the new index), and (b) the owner's user id of a calendar.
  files: app/Dav/Backend/AppCalendarsPDO.php, app/Dav/Services/CalendarSharing.php
  depends: TASK-1
  risk: low — read-only queries; keeps `pgsql` access inside `app/Dav` (STANDARDS 5.5).

TASK-5: Create `App\Api\Calendars\Jobs\InvalidateSharedCalendarCache` (`ShouldQueue, ShouldBeUniqueUntilProcessing`, constructor `int $calendarId` only, `uniqueId()` = calendarId, `$uniqueFor = 60`), styled like `RefreshCalendarCache`. `handle()` uses TASK-4(a) and, for each user, `forgetByPrefix(['calendar_events', $userId, $calendarId])` + `RefreshCalendarCache::dispatch($userId)`. The editor is not excluded (brief's recommendation).
  files: app/Api/Calendars/Jobs/InvalidateSharedCalendarCache.php (new)
  depends: TASK-4
  risk: low — touches only cache keys, using existing patterns; the cache-key prefix must match `CalendarService::CACHE_KEY_EVENTS` exactly.

TASK-6: Make `CalendarService` keep the owner's cached calendar list fresh: `acceptInvite()` forgets the owner's list (owner via TASK-4(b) from the returned calendarId), `revokeShare()` forgets the owner's list (add a `User $owner` parameter and pass `$request->user()` from `CalendarController::revokeShare`), and `unsubscribe()` forgets the owner's list (look up the owner via TASK-4(b) **before** the delete).
  files: app/Api/Calendars/Services/CalendarService.php, app/Api/Calendars/Controllers/CalendarController.php
  depends: TASK-2, TASK-4
  risk: medium — the brief calls these the main risk; the owner lookup in `unsubscribe` must happen before the instance is deleted.

TASK-7: Dispatch `InvalidateSharedCalendarCache` from `CalendarService::createEvent`, `updateEvent`, `destroyEvent`, `destroyEventOccurrence`, `updateEventOccurrence` (only on success, next to the existing `forgetByPrefix`) and from `moveEvent` for each of `$from`/`$to` independently, each only when `$calendar->isShared || $calendar->solytoShared`; no delay. Use one private helper (STANDARDS 4.4).
  files: app/Api/Calendars/Services/CalendarService.php
  depends: TASK-3, TASK-5
  risk: low — adds a conditional dispatch next to existing lines; the editor's synchronous forget stays.

TASK-8: In `CacheInvalidationPlugin::invalidateCalendarEvents()`, select `calendarid, access, solyto_shared` in the existing instance lookup, and when `access > 1 || solyto_shared`, dispatch `InvalidateSharedCalendarCache` with the existing 10 s delay, inside the existing try/catch.
  files: app/Dav/Plugins/CacheInvalidationPlugin.php
  depends: TASK-1, TASK-5
  risk: medium — runs on every DAV object write; it must not throw or change DAV responses (the try/catch already covers it), and `->value()` becomes `->first()`.

TASK-9: Tests for the migration backfill and the flag lifecycle: the backfill sets the flag on the owner row with an accepted sharee, not with only a pending one, never on sharee rows. Lifecycle: false after share, true after accept, stays true when one of two sharees is revoked, false after the last revoke, false after the sharee unsubscribes, false after the sharee's account is deleted (`DavService::deleteForUser`).
  files: app/Dav/Tests/DavServiceTest.php (or a new app/Dav/Tests/SharedCalendarFlagTest.php), tests/ (backfill test, run the migration's `up()` against seeded rows)
  depends: TASK-1, TASK-2, TASK-3
  risk: low — tests only; the backfill test has to seed rows and then run the migration's `up()` (or its backfill) again, since the test DB is already migrated.

TASK-10: Tests for the job and dispatch: the job clears `calendar_events_{user}_{calendarId}*` for the owner and accepted sharees but not pending sharees or unrelated users, queues `RefreshCalendarCache` per affected user, and is unique per calendar. API dispatch: pushed for owner/sharee edits of a shared calendar, not for an unshared one, `moveEvent` shared→unshared pushes once. DAV PUT/DELETE by owner and sharee pushes, unshared doesn't. Use `Queue::fake()` and the spy pattern in `DavApiConsistencyTest.php:630-685`.
  files: app/Api/Calendars/Tests/CalendarApiTest.php, app/Api/Calendars/Tests/InvalidateSharedCalendarCacheTest.php (new), app/Dav/Tests/DavApiConsistencyTest.php
  depends: TASK-5, TASK-7, TASK-8
  risk: low — tests only.

TASK-11: End-to-end and calendar-list tests in `CalendarApiTest`: owner and sharee both load `GET /calendars/events/{Y-m}`, the sharee creates an event, the owner's next load includes it, and the reverse (sync queue). After accept, the owner's cached calendar list has `solytoShared = true`; after the last revoke or unsubscribe it's false. Then run the full suite with the DAV suites (`CalDavProtocolTest`, `DavIsolationTest`, `DavClientFixtureTest`) unchanged, plus `composer analyse`.
  files: app/Api/Calendars/Tests/CalendarApiTest.php
  depends: TASK-6, TASK-7, TASK-8, TASK-9, TASK-10
  risk: low — tests only; DAV suites passing unchanged is the "DAV didn't notice" check.

## Open questions (from the brief, not in scope unless the owner says so)

- **Owner deletes a shared calendar** (`CalendarService::destroy()`): sharees
  keep a stale list (24 h) and stale events. Not planned here; the brief asks
  "include or separate entry?" and has no answer yet. Suggest a separate
  backlog entry.
- **Owner renames/recolours:** sharee rows carry their own `displayname` /
  `calendarcolor` (copied in `updateInvites`, `AppCalendarsPDO.php:443-447`),
  and `getCalendarByIdCustom` reads only the caller's own row plus `calendars`
  (synctoken, components). So nothing a sharee sees comes from the owner's
  instance row, and nothing needs invalidating. Not planned.
