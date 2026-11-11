# Tasks: Calendar event queries ignore the time range and read the whole calendar

id: as
analyst: analyst
date: 2026-11-10

<!-- Produced by @analyst from brief.md. -->

## Scope

Option B from the brief, applied to `AppCalendarsPDO::getExpandedEventsCustom` only, plus the tests in brief §5.

**DAV hard rule flag:** this changes how the DAV backend reads `calendarobjects` on the `pgsql` connection. The schema, the connection and the routes stay the same. The brief asks for this change explicitly. Reviewers should still confirm it and call it out in the verdict.

Out of scope:
- the optional `(calendarid, componenttype, firstoccurence)` index. That is a schema change and needs its own approval.
- any change to the PHP occurrence and overlap loop (`:270-374`). It stays the exact filter.
- `getEventsCustom`, `CalendarService`, the cache keys, `CacheInvalidationPlugin`.

Note: `vendor/` is missing in this worktree (`vendor/sabre/dav/...` does not exist). Before running `composer test` / `composer analyse`, run `composer install` from the existing lock file. That syncs the existing dependencies and adds no new packages.

## Task breakdown

TASK-1: Replace the `parent::calendarQuery(...)` call and the per-URI `getCalendarObject()` re-fetch in `getExpandedEventsCustom` with one prepared statement on `$this->pdo`: `SELECT id, uri, etag, calendardata FROM {$this->calendarObjectTableName} WHERE calendarid = ? AND componenttype = 'VEVENT' AND lastoccurence > ? AND firstoccurence < ?`.
  files: app/Dav/Backend/AppCalendarsPDO.php
  depends: none
  risk: medium. It changes a DAV backend read path that all three calendar read paths use, but the PHP loop below still pins the exact results.
  notes:
  - Bind Unix timestamps padded by one day on each side: `$start->getTimestamp() - 86400` and `$end->getTimestamp() + 86400`. Sabre stores floating all-day DATE values as UTC, so the stored values can differ from the user's wall-clock window by up to 14 h (brief §4). Add a short comment explaining the padding. It is a non-obvious constraint.
  - Keep the `is_resource($row['calendardata'])` → `stream_get_contents` handling. PostgreSQL returns `bytea` as a stream.
  - In the loop, use the row's `id`, `uri` and `etag` instead of `$obj[...]` and `$uri`. Leave the rest of the loop unchanged.
  - Put the query in its own small method that returns the candidate rows, for example `getEventObjectsInRange(CalendarDTO, start, end): array`, so TASK-2 can assert which objects were read (the brief offers this as an option). Keep it as narrow as `getEventsCustom`. Do not add new abstractions.

TASK-2: Add a test that puts an event a year before the window and a VTODO (with DUE/DTSTART inside the window) in the same calendar as an in-window event, and asserts that neither one is returned and neither one is read (the candidate URIs from the TASK-1 method contain only the in-window object).
  files: app/Dav/Tests/EventOccurrenceServiceTest.php (or a new app/Dav/Tests/EventTimeRangeQueryTest.php using the same `davUser`/`davRequest`/`davIcs` helpers)
  depends: TASK-1
  risk: low. Test only.
  notes: Also include a recurring series that started before the window, one infinite and one finite whose last occurrence falls inside the window. Both must stay candidates, which pins the `lastoccurence` behaviour from brief §4.

TASK-3: Add boundary tests for an all-day event on the window's first day and one on its last day, for a user at UTC+14 (`Pacific/Kiritimati`) and a user at UTC-12 (`Etc/GMT+12`). Assert that both are returned, so the ±1 day SQL padding loses nothing.
  files: app/Dav/Tests/EventOccurrenceServiceTest.php or the new test file from TASK-2; follow the existing timezone tests in app/Api/Calendars/Tests/CalendarApiTest.php for setup
  depends: TASK-1
  risk: low. Test only, but it guards the main regression risk of TASK-1.
  notes: Build the window in the user's timezone, the same way `CalendarService::listWidgetEvents` does (3-day window). Optionally add the one-day-outside counterpart (an all-day event the day after the window) to confirm the PHP filter still drops the padded extras.

TASK-4: Run the full `composer test` and `composer analyse` (both must exit 0). Re-run the brief's throwaway N=50/N=500 measurement and do not commit it. Record the result and the DAV flag in implementation.md.
  files: docs/jobs/as_calendar-event-queries-ignore-the-time-range-and-read-the-whole-calendar/implementation.md
  depends: TASK-1, TASK-2, TASK-3
  risk: low. Verification and documentation only.
  notes: In "Known issues / follow-ups", list the pre-deploy production check (`SELECT count(*) FROM calendarobjects WHERE componenttype='VEVENT' AND firstoccurence IS NULL` should be 0) and the deferred index as a separate, approval-gated follow-up.

## Unclear / for the reviewer

- Edge case not covered by the brief: Sabre computes `firstoccurence` from the master's DTSTART. If a moved override (RECURRENCE-ID) sits earlier than the master's first occurrence, the new SQL bound could exclude it. That is rare, and the risk is the same as Sabre's own CalDAV `calendar-query`. Do not add handling for it. Mention it in implementation.md if the developer confirms it can happen.
