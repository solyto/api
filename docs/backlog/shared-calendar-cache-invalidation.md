# Backlog: invalidate sharees' calendar caches when a shared calendar changes

created: 2026-11-13

## What

When one user changes an event in a shared calendar, the other users who have
that calendar keep seeing the old data until their cache expires. Make event
writes clear (and re-warm) the caches of everyone else who has the calendar,
in the background, without adding latency to the write and without touching
DAV behaviour.

Agreed approach (discussed 2026-11-13):

1. A solyto-only flag `solyto_shared` on the **owner's** `calendarinstances`
   row, true while the calendar has at least one accepted sharee.
2. On an event write, dispatch a queued job only if the calendar is shared:
   the editor is a sharee (`CalendarDTO::isShared`) or the owner's row has
   `solyto_shared`.
3. The job looks up everyone else with an accepted instance of the calendar
   and clears + re-warms their event caches.

## Why

Reported case: a user shares a calendar with a friend, the friend edits it,
the owner doesn't see the change. It's the cache, not the data.

### How the cache works today

- Events are cached **per user**:
  `calendar_events_{userId}_{calendarId}_{yearMonth}` (and `..._widget_{date}`,
  `..._today_{date}` under the same prefix), in `CalendarService::listEvents()`
  / `listWidgetEvents()` / `listTodayEvents()`
  (`app/Api/Calendars/Services/CalendarService.php:89-160`). Each month's entry
  holds a ±3-month window.
- TTLs (`CalendarService.php:20-23`): current month 15 min, future months 1 h,
  past months **7 days**, widget 1 h, today 15 min. Calendar list 24 h.
- Every invalidation clears only the **editor's** keys:
  - API writes: `createEvent`, `moveEvent` (both calendars), `updateEvent`,
    `destroyEvent`, `destroyEventOccurrence`, `updateEventOccurrence`
    (`CalendarService.php:167-219`) call
    `forgetByPrefix([calendar_events, $user->id, calendarId])`.
  - DAV writes: `CacheInvalidationPlugin::invalidateCalendarEvents()`
    (`app/Dav/Plugins/CacheInvalidationPlugin.php:103-135`) resolves the user
    from the request path, clears their keys and queues
    `RefreshCalendarCache` for them only. Called from `onCalendarObjectWrite`
    and `onAfterUnbind` (`:37-75`).
  - Import: `CalendarImport::clearCache()` (`app/Dav/Services/CalendarImport.php:153-160`).
- `calendarId` is the same for owner and sharees (one `calendars` row, one
  `calendarinstances` row per user), but the user id is in the key, so the
  other users' entries survive.

### Why "is this calendar shared?" isn't readable today

Sabre models sharing as extra `calendarinstances` rows. Sharing calendar 42
with a friend:

- Owner row: `access = 1` (column default, `ACCESS_SHAREDOWNER`),
  `share_href` NULL, `share_invitestatus = 2` (column default). **Never
  changes** when the calendar is shared, unshared, accepted or declined.
- New sharee row (`AppCalendarsPDO::updateInvites()`, `:433`): same
  `calendarid`, `access` 2/3, `share_href = mailto:<friend>`,
  `share_displayname = "<name> (<owner>)"`, `share_invitestatus = 1`
  (pending, our override; stock Sabre auto-accepts). Accept sets it to 2.

So a sharee's own row says it's shared (`access > 1`, which is what
`CalendarDTO::isShared` reads), but the owner's row looks exactly like an
unshared calendar's. Finding out from the owner side needs a query by
`calendarid`, and **no index leads with `calendarid`**: the indexes are
`(principaluri, uri)`, `(principaluri, calendarid)`,
`(principaluri, share_href)`
(`database/migrations/2025_12_08_161442_create_dav_tables.php:140-142`).
`getCalendarSharees()` (`AppCalendarsPDO.php`) already does a full scan for
this.

Dispatching a job on *every* write and letting it find out was rejected as
wasteful.

### Why the flag is safe

- **DAV can't see it.** Sabre names its columns in every query on
  `calendarinstances` (no `SELECT *`, no column-less `INSERT`), and so does our
  code; checked in `vendor/sabre/dav/lib` and `app/` on 2026-11-13. The one
  `INSERT … SELECT` that copies the owner row to a sharee (`updateInvites`)
  lists its columns, so sharee rows get the default.
- **It only fails in the harmless direction.** The only thing that makes a
  sharee accepted is our `acceptShare()` (nothing else writes
  `share_invitestatus = 2`; Sabre's `shareReply` isn't implemented by the PDO
  backend; DAV-client shares go through our `updateInvites` and start
  pending). So:
  - *wrongly false* would mean stale sharee caches (today's behaviour). Can't
    happen if `acceptShare` sets it and the backfill is right.
  - *wrongly true* (a share removed through a path we don't hook, e.g. a DAV
    client unsharing, or a sharee deleting their instance over DAV) costs one
    job that finds nobody. Acceptable, so **no DAV flow needs a hook**,
    including `updateInvites`.
- Worst case if anything here is wrong: caches are stale until TTL, as now.
  Calendar data itself is never touched.

## Steps

1. **Migration** on the `pgsql` connection, driver-aware like
   `2025_12_11_215552_add_color_to_addressbooks.php` (pgsql branch + SQLite
   branch for tests):
   - `ALTER TABLE calendarinstances ADD COLUMN solyto_shared BOOLEAN NOT NULL
     DEFAULT FALSE` (SQLite: `DEFAULT 0`).
   - `CREATE INDEX calendarinstances_calendarid ON calendarinstances (calendarid)`.
   - Backfill: set `solyto_shared = true` on rows with `access = 1` whose
     `calendarid` has a row with `access > 1 AND share_invitestatus = 2`
     (query builder `whereIn` subquery works on both drivers).
   - `down()`: drop the index first (SQLite can't drop an indexed column),
     then the column.
   - Comment in the migration: our column on Sabre's table; keep it if a Sabre
     upgrade ever migrates `calendarinstances`.
   - Show the migration to the user before running it (`docs/CLAUDE.md`).
   - Runs automatically on deploy and on self-hosted installs
     (`dockerhub/api/api-startup.sh`: `php artisan migrate --force`). On
     Postgres 17 the `ADD COLUMN` with a constant default is metadata-only; the
     index build blocks writes on the table for milliseconds.

2. **Maintain the flag** in `AppCalendarsPDO`, one private helper that
   recomputes rather than toggles (idempotent, can't drift):
   ```sql
   UPDATE calendarinstances SET solyto_shared = EXISTS(
       SELECT 1 FROM calendarinstances s
       WHERE s.calendarid = :id AND s.access > 1 AND s.share_invitestatus = 2)
   WHERE calendarid = :id AND access = 1
   ```
   Call it after:
   - `acceptShare()` (`:512`). Needs the `calendarid` of the accepted row
     (select it in the same scoped lookup).
   - `unshareCalendar()` (`:536`, owner revokes).
   - Sharee unsubscribe: `Calendars::delete()` → `deleteCalendarCustom()`
     (`:207`) when the instance has `access > 1`. Read the `calendarid` from
     the DTO before deleting.
   - `deleteSharedInstancesCustom()` (`:545`, user deletion via
     `Calendars::deleteForUser()`): collect the affected `calendarid`s before
     the delete, recompute each after.
   - `declineShare()` doesn't need it: after the invite-authorization fix it
     only deletes pending rows, which never set the flag.

3. **Read the flag.**
   - API path: add `public bool $solytoShared = false` to `CalendarDTO`
     (`app/Dav/DTOs/CalendarDTO.php`), filled for owner rows in
     `listCalendarsCustom()` (`AppCalendarsPDO.php:38`),
     `getCalendarByIdCustom()` (`:67`) and `getCalendarByNameCustom()`
     (`:121`). `parent::getCalendarsForUser()` builds its own column list, so
     `listCalendarsCustom` needs a separate lookup (e.g. one query for the
     principal's `access = 1 AND solyto_shared` instance ids). The calendar list
     is cached 24 h, so:
     - `CalendarService::acceptInvite()` must also forget the **owner's**
       calendar list (today: only the recipient's). It needs the owner; return
       the `calendarid` from `acceptShare` and resolve the owner, or forget by
       looking up the owner principal.
     - `revokeShare()` (`CalendarService.php:233`) and `unsubscribe()`
       (`:240`) likewise must forget the owner's calendar list.
     - If the owner's list is stale, the flag is stale-false for up to 24 h,
       i.e. today's behaviour. Get these right; they're the main risk.
   - DAV path: in `CacheInvalidationPlugin::invalidateCalendarEvents()`, the
     query at `:107-112` already looks up the instance; select `access` and
     `solyto_shared` along with `calendarid` (no extra round trip).

4. **New job** `App\Api\Calendars\Jobs\InvalidateSharedCalendarCache`, next to
   `RefreshCalendarCache` and in its style:
   - `ShouldQueue, ShouldBeUniqueUntilProcessing`, `uniqueId()` =
     `calendarId`, `$uniqueFor = 60`, so a DAV sync burst (one request per
     object) runs it once.
   - `handle()`: select `principaluri` from `calendarinstances` where
     `calendarid = ? AND (access = 1 OR share_invitestatus = 2)` (owner plus
     accepted sharees; pending sharees never load events because
     `listEvents` filters them), map principals to user ids (principal is
     `principals/<email>`, see `DavHelper::getPrincipalUri()`), then for each:
     `forgetByPrefix([calendar_events, userId, calendarId])` and
     `RefreshCalendarCache::dispatch(userId)` (it already coalesces per user).
   - Recommendation: **don't exclude the editor**. With uniqueness per
     calendar, two editors in one burst would share one job, so excluding "the"
     editor is ambiguous. Clearing the editor again costs one cache rebuild.
     If excluded anyway, the editor id must not be part of the unique key.
   - Delay: none needed for API writes. For DAV, match the existing 10 s delay
     so the burst finishes first.

5. **Dispatch points**, only when `$calendar->isShared || $calendar->solytoShared`,
   next to the existing synchronous `forgetByPrefix` (keep that, so the
   editor sees their own change at once):
   - `CalendarService::createEvent`, `updateEvent`, `destroyEvent`,
     `destroyEventOccurrence`, `updateEventOccurrence`
     (`CalendarService.php:167-219`).
   - `moveEvent` (`:174`): check and dispatch for **both** `$from` and `$to`.
   - `CacheInvalidationPlugin::invalidateCalendarEvents()` (`:103`), using the
     values from step 3.
   - `CalendarImport::clearCache()` (`CalendarImport.php:153`): only matters
     if import can write into a shared calendar. Check; probably not
     (imports create the user's own calendars).

6. **Tests** (`docs/TESTING.md`; queue is `sync` in tests, cache is `array`, so
   use `Queue::fake()` / cache spies like
   `app/Dav/Tests/DavApiConsistencyTest.php:630-685`):
   - Migration: backfill sets the flag on the owner row of a calendar with an
     accepted sharee, not with only a pending one, not on sharee rows.
   - Flag lifecycle (`DavServiceTest` / `CalendarApiTest`): false after share
     (pending), true after accept, stays true when one of two sharees is
     revoked, false after the last is revoked, false after the sharee
     unsubscribes, false after the sharee's account is deleted.
   - API dispatch: owner edits a shared calendar → job pushed; owner edits an
     unshared calendar → **not** pushed; sharee edits → pushed; `moveEvent`
     from a shared into an unshared calendar → pushed once for the shared one.
   - DAV dispatch: PUT/DELETE of an object by owner and by sharee in a shared
     calendar → job pushed; in an unshared calendar → not pushed.
   - Job: clears `calendar_events_{other}_{calendarId}*` for the owner and
     accepted sharees, not for pending sharees or unrelated users; queues
     `RefreshCalendarCache` per affected user; unique per calendar.
   - End to end (`CalendarApiTest`): owner and sharee both load the month;
     sharee creates an event; owner's next `GET /calendars/events/{Y-m}`
     includes it (and the reverse).
   - Calendar list: after accept, the owner's cached calendar list carries
     `solytoShared = true`.
   - Run the DAV suites (`app/Dav/Tests/`: protocol, isolation, client
     fixtures) unchanged as the "DAV didn't notice" check.

## Out of scope

- The invite accept/decline authorization fix: done in `88963e4`
  (`acceptShare()` / `declineShare()` are scoped to the caller's own pending
  invite), so step 2 can hook into `acceptShare()` safely.
- Sharing a second calendar with the same person returns 500 (unique
  `(principaluri, share_href)` index + `mailto:` hrefs, inherited from
  Sabre's schema).
- `forgetByPrefix()` uses Redis `KEYS` (`app/Shared/Services/UserCacheService.php:113`),
  which is O(keyspace). The job multiplies its calls by the number of
  sharees. Existing behaviour; switching to `SCAN` is a separate entry if it
  shows up.
- Shorter TTLs as a substitute. Not needed once invalidation is correct.

## Open questions

- **Owner deletes a shared calendar.** `CalendarService::destroy()`
  (`:82`) → Sabre deletes all instances, but sharees keep the calendar in
  their cached list (24 h) and its events in their event cache. Same
  pattern, but the sharee lookup must happen **before** the delete. Include
  here or separate entry?
- Owner renames/recolours: sharees have their own copies of those columns, so
  nothing to invalidate. Confirm no sharee-visible field comes from the owner
  row.
- Alternative considered and not chosen: no column, just an indexed
  `EXISTS (… calendarid = ? AND access > 1 AND share_invitestatus = 2)` per
  write. It's one indexed query per write instead of none; the user preferred
  the flag. Revisit only if keeping the flag in sync turns out fiddly.

## Notes

- Check: a sharee's event change shows up in the owner's month view on the
  next request after the job ran (and vice versa), with no job dispatched
  for writes to unshared calendars, and the DAV test suites pass unchanged.
