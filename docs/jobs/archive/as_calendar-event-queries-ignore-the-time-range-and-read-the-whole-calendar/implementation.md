# Implementation: Calendar event queries ignore the time range and read the whole calendar

id: as
developer: developer
date: 2026-11-10

## Summary

Option B from the brief. `AppCalendarsPDO::getExpandedEventsCustom` now reads its candidate objects with one prepared statement filtered by `componenttype = 'VEVENT'` and `lastoccurence`/`firstoccurence`, with the bounds padded by one day on each side. This replaces Sabre's `calendarQuery` (which ignored the malformed filter) and the N+1 `getCalendarObject` re-fetches. The PHP occurrence and overlap loop is unchanged and is still the exact filter.

**DAV hard rule flag:** this changes how the DAV backend reads `calendarobjects` on the `pgsql` connection. It is a read-only change. The schema, the connection and the DAV routes are untouched. Reviewers should confirm it.

## Changes

TASK-1: `app/Dav/Backend/AppCalendarsPDO.php`
- New `getEventObjectsInRange(CalendarDTO, start, end): array`. It runs a single `SELECT id, uri, etag, calendardata ... WHERE calendarid = ? AND componenttype = 'VEVENT' AND lastoccurence > ? AND firstoccurence < ?` with `start - 86400` / `end + 86400`, and keeps the `bytea` stream handling.
- The etag stays quoted (`"..."`), the way Sabre's `getCalendarObject()` returned it, so the expanded `EventDTO`s are unchanged.
- `getExpandedEventsCustom` loops over those rows and uses `$obj['uri']` instead of `$uri`. Nothing else in the loop changed.

TASK-2 / TASK-3: new `app/Dav/Tests/EventTimeRangeQueryTest.php`
- The calendar holds an in-window event, an event a year earlier, a VTODO inside the window, an infinite daily series from 2029, a finite series ending inside the window, and a finite series that ended before it. The test asserts that the candidate URIs are exactly `finite.ics`, `infinite.ics` and `inside.ics`, and that `listExpanded` returns only those titles.
- It also puts all-day events on the day before, the first day, the last day and the day after a 3-day window built like `listWidgetEvents`, for `Pacific/Kiritimati` (UTC+14) and `Etc/GMT+12` (UTC-12). Only the first-day and last-day events are returned.

TASK-4: verification
- `composer analyse`: exit 0, no errors.
- `composer test`: all 1359 tests pass. Across four full runs, some runs had one or two failures in `TimeTrackingTest` ("creates a short entry": `60 is less than 60`). That test uses a random factory duration and does not touch calendars. The same run passes on a re-run.
- The many "warnings" all come from the sandbox having no `.env` file (phpdotenv `file_get_contents`). They are not test problems.
- Re-ran the throwaway measurement (not committed): one calendar with N one-hour events on consecutive days and a 3-day window.

| N | candidates read | events returned | `listExpanded` time |
|---|---|---|---|
| 50  | 5 | 3 | 2.5 ms (includes first-call warm-up) |
| 500 | 5 | 3 | 1.3 ms |

Before the change it was N candidates, 8 ms and 73 ms. The time is now constant in N. The 2 extra candidates come from the ±1 day padding and the PHP loop drops them.

## Known issues / follow-ups

- **Pre-deploy production check:** `SELECT count(*) FROM calendarobjects WHERE componenttype='VEVENT' AND firstoccurence IS NULL` should return 0. Rows with NULL occurrence columns would no longer be listed.
- **Deferred index (schema change, needs its own approval):** `CREATE INDEX ON calendarobjects (calendarid, componenttype, firstoccurence)`. Measure on production-sized calendars first.
- **Moved overrides (confirmed possible):** Sabre's `getDenormalizedData` takes `firstoccurence` from the DTSTART of the first non-VTIMEZONE component, normally the master. A RECURRENCE-ID override moved more than a day earlier than the master's first occurrence would fall outside the SQL bound when it is the only occurrence in the window. Sabre's own CalDAV `calendar-query` has the same limitation. Not handled, as the analyst asked.
- **Unrelated flaky test:** `TimeTrackingTest` "creates a short entry" fails now and then because its factory can produce exactly 60 minutes.
- **Environment:** this worktree had no `storage/framework` directories and no `vendor/`. I created them locally (both are git-ignored) and ran `composer install` from the lock file. `.env.testing` shows as modified because it is mounted as a character device in the sandbox. I left it alone.
