# Implementation: invalidate sharees' calendar caches when a shared calendar changes

id: extend
developer: developer
date: 2026-11-13

## Summary

Event writes to a shared calendar now queue `InvalidateSharedCalendarCache`.
The job clears and re-warms the event caches of the owner and every accepted
sharee. "Shared" is read without extra queries: from the editor's own instance
(`access > 1`) or from a new `solyto_shared` flag on the owner's
`calendarinstances` row. The flag is recomputed whenever a share is accepted,
revoked or left. Writes to unshared calendars queue nothing. The editor's
synchronous cache forget is unchanged, and DAV responses are unchanged (all
DAV suites pass without edits).

`composer test`: 1415 passed. `composer analyse`: no errors.

### Migration (DAV schema change, approved in the brief; not run against a real database)

`database/migrations/2026_11_13_000001_add_solyto_shared_to_calendarinstances.php`:

```php
public function up(): void
{
    $db = DB::connection('pgsql');
    $default = $db->getDriverName() === 'pgsql' ? 'FALSE' : '0';

    $db->statement("ALTER TABLE calendarinstances ADD COLUMN solyto_shared BOOLEAN NOT NULL DEFAULT {$default}");
    $db->statement('CREATE INDEX calendarinstances_calendarid ON calendarinstances (calendarid)');

    $db->table('calendarinstances')
        ->where('access', 1)
        ->whereIn('calendarid', fn (Builder $query) => $query
            ->select('calendarid')
            ->from('calendarinstances')
            ->where('access', '>', 1)
            ->where('share_invitestatus', 2))
        ->update(['solyto_shared' => true]);
}

public function down(): void
{
    $db = DB::connection('pgsql');

    // SQLite can't drop an indexed column.
    $db->statement('DROP INDEX calendarinstances_calendarid');
    $db->statement('ALTER TABLE calendarinstances DROP COLUMN solyto_shared');
}
```

## Changes

TASK-1: Added the migration above and an allowlist entry in
`tests/Unit/MigrationCompatibilityTest.php`. It's flagged because it uses
`DB::statement` and `update` in `up()`.

TASK-2: `AppCalendarsPDO::recomputeSharedFlag()` (private) runs the brief's
idempotent `UPDATE … SET solyto_shared = EXISTS(…)` on the owner row. It is
called from `acceptShare()` (which now looks up the pending row, updates it by
id and returns its `calendarid` as `?int`), `unshareCalendar()`,
`deleteCalendarCustom()` for sharee instances, and
`deleteSharedInstancesCustom()` (calendar ids collected before the delete).
`CalendarSharing::acceptInvite()` returns `?int`, and
`CalendarService::acceptInvite()` checks for `null`.

TASK-3: Added `CalendarDTO::$solytoShared`. It's filled in
`getCalendarByIdCustom()` / `getCalendarByNameCustom()` (column added to their
selects) and in `listCalendarsCustom()` (one query for the principal's flagged
owner instances). `CalendarResource` doesn't expose it.

TASK-4: Added `AppCalendarsPDO::getCalendarMemberIds()` (owner + accepted
sharees, `calendarid = ? AND (access = 1 OR share_invitestatus = 2)`) and
`getCalendarOwnerId()`. Both map principals to user ids. They're exposed as
`CalendarSharing::listMemberIds()` / `ownerId()`, so no `pgsql` access was
added under `app/Api`.

TASK-5: New `App\Api\Calendars\Jobs\InvalidateSharedCalendarCache`
(`ShouldBeUniqueUntilProcessing`, unique per calendar id, `$uniqueFor = 60`).
For each member it runs `forgetByPrefix(['calendar_events', userId, calendarId])`
and `RefreshCalendarCache::dispatch(userId)`. The editor isn't excluded.
`CalendarService::CACHE_KEY_EVENTS` is now `public` so the job uses the same
prefix and can't drift from it.

TASK-6: `CalendarService` forgets the owner's calendar list on accept (owner
looked up from the returned calendar id), on revoke (new `User $owner`
parameter, passed from `CalendarController::revokeShare`) and on unsubscribe
(owner looked up before the delete). The forget goes through a private
`forgetCalendarList()`.

TASK-7: A private `CalendarService::invalidateSharedCalendar()` dispatches
the job when `isShared || solytoShared`. It's called after successful
`createEvent`, `updateEvent`, `destroyEvent`, `destroyEventOccurrence` and
`updateEventOccurrence`, and for both `$from` and `$to` in `moveEvent`. There
is no delay.

TASK-8: `CacheInvalidationPlugin::invalidateCalendarEvents()` now selects
`calendarid, access, solyto_shared` in its existing lookup. When the
calendar is shared it dispatches the job with the existing 10 s delay, inside
the existing try/catch.

TASK-9: New `app/Dav/Tests/SharedCalendarFlagTest.php` covers the flag
lifecycle (pending, accept, two sharees with one and then both revoked,
unsubscribe, sharee account deletion), the DTO field on
list/get/getByName, and the backfill (re-runs the migration's `down()` +
`up()` against seeded accepted and pending shares).

TASK-10: New `app/Api/Calendars/Tests/InvalidateSharedCalendarCacheTest.php`
checks that the job clears and re-warms the owner and accepted sharee but not
the pending sharee or an unrelated user, and that it is unique per calendar.
`CalendarApiTest` checks API dispatch: owner and sharee create, update and
delete; nothing for an unshared calendar; a move from shared to unshared
pushes once. `DavApiConsistencyTest` checks DAV dispatch: PUT and DELETE by
owner and by sharee push a delayed job, and an unshared calendar pushes
nothing.

TASK-11: `CalendarApiTest` has the end-to-end check (a sharee's new event
appears in the owner's warmed month view, and the reverse) plus the owner's
cached list flag through accept, revoke and unsubscribe. Both fail if the
dispatch or the owner-list forgets are removed (checked).
`forgetByPrefix()` is a no-op on the test array store, so this describe binds
a `UserCacheService` double that does the same prefix delete on the array
store. The DAV suites run unchanged.

## Known issues / follow-ups

- Open question from the brief, not done: when the owner deletes a shared
  calendar (`CalendarService::destroy()`), sharees keep a stale list and
  stale events until TTL. This probably needs its own backlog entry, and the
  member lookup would have to run before the delete.
- `UserCacheService::get()` treats falsy values as misses, so an empty month
  is never cached and is re-read on every request. This is existing behaviour
  and out of scope.
- Environment: `vendor/` was missing, and the worktree needed
  `storage/framework/*` and an empty `.env` (all gitignored) before tests
  could run. `.env.testing` is a `/dev/null` device mount that shows as
  modified in `git status` and can't be staged. It was left untouched.
- One full-suite run had 2 flaky failures in `ExportTest`
  (`FinanceWealthExportService`). They passed alone and on every later full
  run, and that code wasn't touched here.
