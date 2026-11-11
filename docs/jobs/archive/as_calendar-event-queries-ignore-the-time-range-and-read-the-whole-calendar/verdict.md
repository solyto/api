# Verdict: Calendar event queries ignore the time range and read the whole calendar

id: as
reviewer: reviewer
date: 2026-11-10

## Review

Reviewed against `git diff main...HEAD`. The only source changes are `app/Dav/Backend/AppCalendarsPDO.php` and the new `app/Dav/Tests/EventTimeRangeQueryTest.php`. Everything else is job docs. Nothing changed outside the scope.

TASK-1: PASS
notes: `getEventObjectsInRange` runs one prepared `SELECT id, uri, etag, calendardata ... WHERE calendarid = ? AND componenttype = 'VEVENT' AND lastoccurence > ? AND firstoccurence < ?`, with the bounds padded by ±86400 s. It keeps the `bytea` stream handling and quotes the etag the way Sabre's `getCalendarObject()` does (`vendor/sabre/dav/lib/CalDAV/Backend/PDO.php:467`), so the `EventDTO`s are unchanged. The loop only swaps `$uri` for `$obj['uri']`. The occurrence and overlap filter is untouched. I checked the bounds against Sabre's `getDenormalizedData` (`PDO.php:646-683`): events without DTEND, all-day events, and finite and infinite RRULEs all get a `lastoccurence` that the padded condition accepts. The padding comment explains a non-obvious constraint, which is appropriate.

TASK-2: PASS
notes: The test asserts that the candidate URIs are exactly `finite.ics`, `infinite.ics` and `inside.ics`. A year-old event, a VTODO inside the window and an ended series are all excluded. `listExpanded` returns only the matching titles. Infinite and finite recurring series that started before the window are pinned.

TASK-3: PASS
notes: The all-day events from the day before through the day after a 3-day window are tested for `Pacific/Kiritimati` and `Etc/GMT+12`. Only the first and last day are returned, which covers both the padding and the PHP drop of padded extras.

TASK-4: PASS
notes: implementation.md records the measurement (constant candidates in N), the DAV flag, the pre-deploy NULL `firstoccurence` check, the deferred index and the moved-override limitation. I re-ran the checks myself: `composer test` exited 0 (1359 passed, 4899 assertions; the warnings all come from the missing `.env` in the sandbox), and `composer analyse` reported `[OK] No errors`.

**DAV hard rule:** I confirm this changes how the DAV backend reads `calendarobjects` on the `pgsql` connection. It is read-only. The schema, the connection and the DAV routes are unchanged. The brief explicitly requested this, so it is flagged here for the merge decision.

## Security

Not run. The query is fully parameterised. The table name comes from Sabre's own property, not from user input.

## Overall

APPROVED

No blockers. Before deploying, run the production check listed in implementation.md (`SELECT count(*) FROM calendarobjects WHERE componenttype='VEVENT' AND firstoccurence IS NULL` should be 0).
