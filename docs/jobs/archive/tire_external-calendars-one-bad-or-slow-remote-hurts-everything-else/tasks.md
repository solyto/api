# Tasks: external calendars — one bad or slow remote hurts everything else

id: tire
analyst: analyst
date: 2026-11-14

<!-- Produced by @analyst from brief.md. -->

Branch verified: worktree HEAD is `feature/tire_external-calendars-one-bad-or-slow-remote-hurts-everything-else`, which matches the brief.

## Task breakdown

TASK-1: Add a `failDelete` switch to `FakeCalDavServer` so a test can make only DELETE fail (503) while GET/PUT/REPORT keep working.
  files: tests/Support/FakeCalDavServer.php
  depends: none
  risk: low — test-only helper. Today it can only fail everything (`$down`) or send a 412 on an If-Match mismatch. A broken object is already possible with `put($name, 'garbage')`, and `$down` covers "remote dead or timed out" for steps 1 and 2.

TASK-2: In `AppCalendarsPDO::remoteState()`, when `collectionState()` throws `ExternalCalendarUnavailableException`, write `['fetched_at' => time(), 'state' => <cached state or ['ctag' => null, 'sync_token' => null]>]` (TTL 0, the same as the success path) before returning, so a dead remote is contacted at most once per `calendars.external.cache_ttl`.
  files: app/Dav/Backend/AppCalendarsPDO.php (remoteState, ~L1207-1226; update the withRemoteState docblock if it no longer matches)
  depends: none
  risk: medium — `getCalendarsForUser()` runs on every DAV request. Check that `markStateStale()` (it sets fetched_at=0 after writes) still forces a refetch. A remote that comes back is now picked up only after up to one TTL, which the brief accepts.
  test: in app/Dav/Tests/ExternalCalendarProxyTest.php, take the remote down, then make two DAV requests (for example PROPFIND on the calendar home) after the TTL has passed. Expect exactly one remote request in `$server->requests`, and the calendar still listed with its last known ctag.

TASK-3: In `AppCalendarsPDO::getExpandedEventsCustom()`, wrap the work on each object (`Reader::read`, DTSTART/DTEND access, RRULE expansion, `EventDTO::fromSabre`) in a try/catch. On failure, or when a VEVENT has no DTSTART, skip that object and log `Log::channel('dav')->warning(...)` with the calendar id and object uri (no calendar data in the log).
  files: app/Dav/Backend/AppCalendarsPDO.php (getExpandedEventsCustom, ~L537-658)
  depends: none
  risk: medium — the loop is long and also handles all-day and RRULE edge cases for native calendars. The guard must not change behaviour for valid data. Catching `\Throwable` versus the VObject `ParseException` plus `\Exception` is a choice to make deliberately. Native data goes through the same loop, which is acceptable and makes it more robust.
  test: in ExternalCalendarApiTest (or ExternalCalendarProxyTest), the remote holds one valid event, one unparseable object and one VEVENT without DTSTART. The event listing returns 200 with only the valid event.

TASK-4: In `AppCalendarsPDO::calendarQuery()` (external branch), guard `validateFilterForObject()` for each object in the same way. Skip and log objects that fail to parse, so they are neither returned nor prefetched.
  files: app/Dav/Backend/AppCalendarsPDO.php (calendarQuery, ~L204-211)
  depends: TASK-3 (same log message/shape; it can be done independently, but keep the two consistent)
  risk: low — small loop. Skipped objects never reach sabre's follow-up multiget, so the REPORT returns only the valid ones.
  test: a DAV calendar-query REPORT against the external calendar with a broken object on the remote returns 207 containing only the valid object.

TASK-5: Make empty external event results cacheable. Recommendation: use the scoped option from the brief, which wraps only the external branch of `CalendarService::collectEvents()` (e.g. store `['events' => [...]]` and unwrap on read, treating any other shape as a miss so entries from the old format expire cleanly). Leave `UserCacheService::get()` unchanged.
  files: app/Api/Calendars/Services/CalendarService.php (collectEvents external branch, ~L200-213)
  depends: none
  risk: low (scoped) / medium (global) — reasons below.
  Why not the global `get()` change: `UserCacheService` has 47 users. Switching to `=== null` would make every `remember()` cache `[]`/`false`/`0`. For example, an empty release list from a temporary Hardcover/TMDB/Discogs outage would then stick for 24 h (`Library*Service::getReleases`, the `Grab*Releases` jobs), and external discovery results would stick for 600 s. `tests/Unit/UserCacheServiceTest.php` also asserts the falsy-as-miss behaviour for both `get()`/`remember()` and `many()`, and `FeedService::getAggregatedItems` relies on `many()`. The brief leaves the choice to implementation. If the developer still goes global, `many()` must change consistently, those unit tests must be updated, and the reviewer should audit the remember() callers listed above.
  test: an external calendar with no events in the window. Two event listings produce exactly one remote REPORT.

TASK-6: Make `CalendarService::moveEvent()` safe when deleting from the source fails. Keep create-in-target first. If the delete throws, delete the new copy from the target, then rethrow so the API still answers with the same error. Run the four cache/shared invalidation lines in a `finally`.
  files: app/Api/Calendars/Services/CalendarService.php (moveEvent, ~L234-246)
  depends: TASK-1 (for the test)
  risk: medium — this was chosen over the brief's "delete source first" option because if the create then fails, the event is lost, while a rollback at worst leaves the old duplicate. The rollback delete can itself fail when the target is external. Catch and log that failure, and don't let it hide the original exception. Native `delete()` returns `false` rather than throwing; whether `false` should also trigger a rollback is not covered by the brief (see open questions).
  test: move an event from an external calendar to a native one with `failDelete = true`. The request fails, the target calendar has no copy, the source still has the event, and the event caches of both calendars were invalidated.

TASK-7: Run `composer test` and `composer analyse` and get both to exit 0.
  files: none
  depends: TASK-1 to TASK-6
  risk: low — the existing external-calendar tests (ExternalCalendarProxyTest, ExternalCalendarApiTest, DavApiConsistencyTest) are the most likely to need updating if they assert remote request counts.

## Open questions

- TASK-6: native `Events::delete()` signals failure by returning `false`, which `moveEvent()` ignores today, so that can also leave a duplicate. The brief only covers the throwing (external) case. The developer could roll back on `false` as well, but that widens scope; confirm before doing it.
- TASK-5: the option choice is left to implementation by the brief; the recommendation above is the scoped wrap.
- No user-facing strings are added, so the translator is not needed. Nothing touches the `pgsql` schema or DAV routes.
