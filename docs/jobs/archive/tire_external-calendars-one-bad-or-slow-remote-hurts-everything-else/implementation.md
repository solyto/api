## Summary

A slow, dead, or misbehaving external CalDAV remote no longer slows down or breaks the rest of the user's calendar. Failed state fetches are remembered for one TTL. Objects that can't be parsed are skipped and logged. Empty external windows are cached. A move whose source delete fails takes its copy back. Each step has a test that was confirmed to fail without the fix. `composer test` and `composer analyse` both exit 0.

## Changes

TASK-1: `tests/Support/FakeCalDavServer.php` gets a `failDelete` switch. When it is on, DELETE answers 503 and every other method keeps working.

TASK-2: `AppCalendarsPDO::remoteState()` now also stores `['fetched_at' => time(), 'state' => last known or empty]` when `collectionState()` fails. A dead remote is then asked at most once per `calendars.external.cache_ttl`. `markStateStale()` still forces a refetch. The `withRemoteState()` docblock was updated. Test: `ExternalCalendarProxyTest` "asks a dead remote for its state at most once per TTL".

TASK-3: the per-object work in `getExpandedEventsCustom()` moved into a private `expandObject()`, called inside a try/catch (`\Throwable`, because odd property types surface as `\Error`). A VEVENT without DTSTART throws, so the whole object is skipped. Skipped objects are logged on the `dav` channel with the calendar id, the uri, and only the exception class, because VObject parser messages can quote calendar data. Native data goes through the same guard. Test: `ExternalCalendarApiTest` "skips remote objects that cannot be read…".

TASK-4: `calendarQuery()` (external branch) guards `validateFilterForObject()` the same way, through the shared `logSkippedObject()`. Skipped objects are neither returned nor prefetched. Test: `ExternalCalendarProxyTest` "leaves remote objects that cannot be parsed out of a calendar-query".

TASK-5: I used the scoped option. `CalendarService::collectEvents()` stores external results as `['events' => [...]]` and treats any other shape as a miss, so old-format entries are simply refetched. `UserCacheService` is unchanged. Test: "caches an empty external window" (two listings, one REPORT).

TASK-6: `CalendarService::moveEvent()` still creates in the target first. If the source delete throws, the new copy is deleted from the target (`removeMovedCopy()`, which logs and swallows its own failure) and the original exception is rethrown, so the API still answers 503. Cache and shared invalidation run in a `finally`. Test: "takes the copy back when moving out of an external calendar fails on the remote delete".

TASK-7: `composer test` and `composer analyse` both exit 0.

## Known issues / follow-ups

- Native `Events::delete()` reports failure by returning `false`. `moveEvent()` still ignores that, so a native source could in theory leave a duplicate. The brief didn't cover this (see the open question in tasks.md).
- On the first full-suite run in this fresh worktree, two `ExportTest` cases failed with an ErrorException. They passed when run alone and on two later full runs. This looks environmental or flaky and is unrelated to this job.
- The worktree had no `vendor/` and no `storage/framework/*` dirs. I ran `composer install` from the lock file and created the gitignored storage dirs. Nothing was committed from either.
