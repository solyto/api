# Implementation: investigate — time-range query can drop recurring series

id: ride
developer: developer
date: 2026-11-14

## Summary

Brief item 1 reproduces: when a DAV client writes an override VEVENT
(RECURRENCE-ID) before its master, sabre stores the override's bounds and the
whole series disappears from the app's range reads (and from sabre's own
calendar-query REPORT). `AppCalendarsPDO` now takes the bounds from the master
in that case, without touching the stored body. A dry-run-by-default command
fixes rows written before the fix. Brief item 2 (2038 cap) is handled in the
app's range query by treating capped rows as unbounded.

## Changes

TASK-1: `app/Dav/Tests/EventTimeRangeQueryTest.php` — a weekly series from
2030-01-07 with the 2030-01-14 occurrence moved, PUT over DAV with the override
first and (as a twin) master first. It asserts the stored bounds, the ETag
(md5 of the PUT body), `getEventObjectsInRange` and `listExpanded` for the
2030-10-14..17 window. Before TASK-2 the override-first case failed:

```
Failed asserting that 1894618800 is identical to 1894010400.
```

Stored values before the fix:

```
override first: firstoccurence 1894618800 (2030-01-14 11:00Z), lastoccurence 1894622400, range uris [], titles []
master first:   firstoccurence 1894010400 (2030-01-07 10:00Z), lastoccurence 2145916800, range uris [series.ics], titles [Series]
```

After TASK-2 both cases pass.

TASK-2: `app/Dav/Backend/AppCalendarsPDO.php` — `getDenormalizedData()` is
overridden: it merges `parent::` with `masterOccurrenceBounds()`. That method
returns null unless the first non-VTIMEZONE component is a VEVENT with a
RECURRENCE-ID and a master with the same UID exists; otherwise it moves the
master first on a parsed copy and returns only `firstOccurence`/`lastOccurence`
from sabre's own computation. ETag, size, UID and component type still come
from the original body, which is stored unchanged. Master-first objects,
override-only objects and VTODO/VJOURNAL are left alone.

TASK-3: `app/Api/Calendars/Commands/RecomputeEventOccurrenceBounds.php` (new),
registered in `bootstrap/app.php`; test in
`app/Api/Calendars/Tests/RecomputeEventOccurrenceBoundsTest.php`.
`php artisan app:calendars:recompute-event-bounds` scans VEVENT rows on the
`pgsql` connection, recomputes bounds via `masterOccurrenceBounds()` for rows
containing a RECURRENCE-ID, and lists rows whose stored bounds differ. With
`--apply` it updates only `firstoccurence`/`lastoccurence`; `calendardata`,
`etag`, `size`, `lastmodified` and `calendarchanges` stay untouched (the body
did not change, so clients need no sync). Unparseable rows are reported and
skipped.

TASK-4: `app/Dav/Backend/AppCalendarsPDO.php` — `getEventObjectsInRange` now
matches `lastoccurence > ? OR lastoccurence >= 2145916800` (sabre's
`MAX_DATE`, as a named const). Test: for a 2039 window an infinite daily
series and one until 2040 are listed, one until 2031 is not (verified to fail
without the clause).

TASK-5: removed `docs/backlog/calendar-time-range-override-first.md` (the
backlog convention deletes entries once converted to a job); this file.

`composer test` (all passing, 5474 assertions) and `composer analyse` both
exit 0.

## Known issues / follow-ups

- ⚑ DAV behaviour change: for override-first objects, sabre's calendar-query
  REPORT now uses the master's bounds too, so DAV clients doing time-range
  queries will start seeing these series where they previously didn't. No
  `pgsql` schema or DAV route change.
- ⚑ Production backfill: run `php artisan app:calendars:recompute-event-bounds`
  as a dry run first and show the output to the maintainer before running it
  with `--apply`. It writes `pgsql` DAV rows (data only). Not run against any
  real database by the agent.
- Real-client component order (Thunderbird, Apple Calendar, DAVx⁵) was not
  verified live; the fix doesn't depend on it, since RFC 5545 doesn't require
  the master first.
- Still open: sabre#1512 — an override moved earlier than the master's
  DTSTART (or later than the series end) is outside the stored bounds and can
  still be missed in windows around it. Not in scope here.
- Sabre's own REPORT still applies the 2038 cap; only the app's range query
  treats it as unbounded.
- Environment note: this fresh worktree lacked the untracked
  `storage/framework/*` and `storage/app/public/user` dirs; two Export tests
  fail in the full suite until `storage/app/public/user` exists. Unrelated to
  this job.
