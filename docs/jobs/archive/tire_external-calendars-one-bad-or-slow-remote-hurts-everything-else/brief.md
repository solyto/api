# Brief: external calendars — one bad or slow remote hurts everything else

type: feature
id: tire
branch: feature/tire_external-calendars-one-bad-or-slow-remote-hurts-everything-else
date: 2026-11-14
author: Leander Muskalla

## What

Four problems in the external CalDAV proxy (e94ce97), all found in the
review of the changes since 1.1.2:

1. **A dead remote slows every DAV request.** `getCalendarsForUser()` calls
   `withRemoteState()` for each external calendar
   (`app/Dav/Backend/AppCalendarsPDO.php:70`). Sabre resolves the calendar
   home on every request. When the remote fails, `remoteState()`
   (`AppCalendarsPDO.php:1217-1221`) returns the old state but never
   refreshes `fetched_at`. So once the 300 s TTL is past, every request
   contacts the remote again. That includes requests to the user's native
   calendars, and each attempt can take up to the 5 s timeout per external
   calendar.
2. **One malformed remote event makes the whole event listing return 500.**
   `getExpandedEventsCustom()` (`AppCalendarsPDO.php:548-552`) runs
   `Reader::read()` and `DTSTART->getDateTime()` on remote data without a
   guard. `CalendarService::collectEvents()` (around line 210) only catches
   `ExternalCalendarUnavailableException`. Native data was validated by
   Sabre when it was written; remote data never is. The failure isn't
   cached, so it repeats on every request. The same happens for DAV
   `calendarQuery`, through `validateFilterForObject`
   (`AppCalendarsPDO.php:207`).
3. **Empty external results are never cached.** `UserCacheService::get()`
   treats any falsy value, including `[]`, as a miss
   (`app/Shared/Services/UserCacheService.php:33`). `remember()` then calls
   the remote again on every listing or widget load when an external
   calendar has no events in the window.
4. **Moving an event out of an external calendar can leave a duplicate.**
   `CalendarService::moveEvent()` (`CalendarService.php:234-246`) creates
   the event in the target first, then deletes it from the source. If the
   remote delete throws (503 or 412), the copy stays, the cache-invalidation
   lines after it never run, and a retry creates a second copy.

## Why

External calendars are meant to behave like local ones. Right now, one flaky
or misbehaving remote server breaks or slows the user's whole calendar,
including native calendars.

## Steps

1. In `remoteState()`, when the remote fails, store
   `['fetched_at' => time(), 'state' => <last known or empty state>]`. A dead
   remote is then retried at most once per TTL.
2. In `getExpandedEventsCustom()` and the `calendarQuery` path, wrap the
   parsing of each object in try/catch. Skip and log objects that can't be
   parsed or have no DTSTART, instead of failing the whole request.
3. Make empty results cacheable. Either:
   - change `UserCacheService::get()` to check `$item === null`. This is
     shared code, so check callers that store `false`/`0`/`''` on purpose; or
   - only for external calendars, wrap the result (e.g. `['events' => []]`)
     so it isn't falsy.
   Decide which while implementing; the first is cleaner if no caller relies
   on the falsy behaviour.
4. In `moveEvent()`, when the source is external, delete in the source
   first and then create in the target. Or, if the delete fails, delete the
   new copy again. In both cases, run the cache invalidation in a `finally`.

## Notes

- Tests: `FakeCalDavServer` can return a broken object, time out, or fail a
  DELETE. Add one test per step.
- Step 1 also covers sabre resolving the home more than once per request,
  which was only a suspected multiplier.
