# Brief: Calendar event queries ignore the time range and read the whole calendar

type: feature
id: as
branch: feature/as_calendar-event-queries-ignore-the-time-range-and-read-the-whole-calendar
date: 2026-11-10
author: Leander Muskalla

Source report: `docs/reports/calendar-time-range-query-performance-2026-11-09.md`

## Summary

**Every event lookup the app makes loads and parses every object in the calendar, whatever date range was asked for.** `AppCalendarsPDO::getExpandedEventsCustom` (`app/Dav/Backend/AppCalendarsPDO.php:238-253`) builds a filter that Sabre's `calendarQuery` does not understand. As a result the time-range SQL condition is never applied. The method then:

1. runs `SELECT uri FROM calendarobjects WHERE calendarid = ?`. That is **all** objects, including past years and VTODOs.
2. fetches each object again with a separate `getCalendarObject()` call (`:258`). That is N+1 queries against the `pgsql` connection.
3. parses each one with `VObject\Reader::read` (`:268`) and only then drops what is outside the window, in PHP (`:323-329`, `:364`).

So the cost grows with the size of the user's **whole calendar history**, not with the size of the window. A user who imported a few years of ICS pays for all of it on every cache miss.

No earlier report covers this. `external-calendars-via-dav-2026-11-08.md` and `vtodo-caldav-sync-2026-10-14.md` touch `AppCalendarsPDO`, but not this query.

## 1. The bug

The app passes this (`AppCalendarsPDO.php:240-248`):

```php
['name' => 'VEVENT', 'prop-filters' => [], 'comp-filters' => [],
 'time-range' => ['start' => $start, 'end' => $end]]
```

Sabre's `calendarQuery` (`vendor/sabre/dav/lib/CalDAV/Backend/PDO.php:762-839`) expects the CalDAV filter tree: a `VCALENDAR` root, with the `VEVENT` comp-filter and its `time-range` **inside** `comp-filters[0]`. It only reads `componenttype` and `time-range` from `comp-filters[0]` (`:779-799`). The app sends `comp-filters` empty, so:

- `$componentType = null`: no `componenttype = 'VEVENT'` condition
- `$timeRange = null`: no `lastoccurence > :start AND firstoccurence < :end` condition
- `$requirePostFilter = false`: no validation either

The top-level `time-range` key is ignored silently. Results are still correct only because the PHP loop filters again afterwards. That is why tests never caught it.

This has been the code since `37f9642` (Initial Commit).

## 2. Measured

I ran a throwaway Pest test (since deleted) on the test setup (SQLite for the DAV connection, in memory). It used one calendar with N single one-hour events on consecutive days and a 3-day window (the widget's window), and compared the app's filter against a correctly shaped Sabre filter for the same window:

| N objects in calendar | URIs from app filter | URIs from correct filter | events returned | `listExpanded` time |
|---|---|---|---|---|
| 50  | 50  | 3 | 3 | 8 ms  |
| 500 | 500 | 3 | 3 | 73 ms |

The time grows linearly, about 0.15 ms per object, with an in-process SQLite and **no network**. In production each of the N extra `getCalendarObject` calls is a round trip to PostgreSQL, so the real cost per object is higher. I did not measure against PostgreSQL.

## 3. Where it hurts

`listExpanded` is the only expanded-event read path. It's called from three places in `CalendarService`, once **per calendar** of the user:

| Caller | Window | Cache TTL | Who triggers it |
|---|---|---|---|
| `listEvents` (`CalendarService.php:88-111`) → `GET /calendars/events/{yearMonth}`, `RefreshCalendarCache` job | ±3 months (~7 months) | 15 min (current month), 1 h (future), 7 d (past) | calendar UI, every month navigated |
| `listWidgetEvents` (`:114-134`) → `GET /widgets/your-day`, `GET /calendars/events/widget` | 3 days | 1 h | home-screen widgets, dashboard |
| `listTodayEvents` (`:139-159`) → `SendDailyDayRemindersCommand`, `IntegrationGateway::todayEvents` | 1 day | 15 min | hourly scheduler (for users at 07:00 local), bot |

The cache absorbs repeats, but every miss pays the full-history cost. Misses are frequent:

- **Every event write** calls `forgetByPrefix([events, user, calendar])` (`:169` and the other write methods), and so does `CacheInvalidationPlugin` for DAV-client writes. The next month view, widget and today lookup then all rebuild from scratch. Each one reads the whole calendar again, and each rebuild is per calendar.
- The **widget and today keys include the date**, so the first request of each day is always a miss.
- Shared calendars are read the same way, so a large shared calendar costs every member.

## 4. Suggested fix

Smallest correct change, in `getExpandedEventsCustom` only:

**Option A: pass a correctly shaped filter (minimal diff).**

```php
$filters = [
    'name' => 'VCALENDAR',
    'is-not-defined' => false,
    'prop-filters' => [],
    'time-range' => null,
    'comp-filters' => [[
        'name' => 'VEVENT',
        'is-not-defined' => false,
        'prop-filters' => [],
        'comp-filters' => [],
        'time-range' => ['start' => $paddedStart, 'end' => $paddedEnd],
    ]],
];
```

With this filter Sabre adds the `componenttype` and `firstoccurence`/`lastoccurence` conditions. However, it then sets `requirePostFilter = true` and runs `validateFilterForObject` on each candidate, which means one more VObject parse per candidate. The N+1 re-fetch in `:258` also stays.

**Option B (recommended): one query of our own.** Replace the `calendarQuery` and per-URI `getCalendarObject` pair with a single prepared statement on `$this->pdo`:

```sql
SELECT id, uri, etag, calendardata
FROM calendarobjects
WHERE calendarid = ? AND componenttype = 'VEVENT'
  AND lastoccurence > ? AND firstoccurence < ?
```

The existing PHP loop stays as it is and remains the exact filter. This gives one query instead of N+1, and one parse per candidate instead of two.

Things the follow-up job must watch:

- **Pad the SQL bounds by at least a day on each side.** Sabre computes `firstoccurence`/`lastoccurence` from floating all-day `DATE` values as UTC (`PDO.php:617-672`). The app then rebases them onto the user's timezone (`AppCalendarsPDO.php:276-288`). For timezones up to UTC±14 the stored timestamps can be off by up to 14 h from the wall-clock window, so `±1 day` on the SQL side keeps the current results exactly. The PHP filter removes the extra rows.
- Infinite recurrences already get `lastoccurence = MAX_DATE` from Sabre, and finite ones get their real last end, so recurring series stay included.
- `lastoccurence`/`firstoccurence` are only filled for objects written through Sabre's `createCalendarObject`/`updateCalendarObject`. All app write paths (`createEventCustom`, `importEventCustom`, `updateEventCustom`) and DAV clients go through those, so existing rows are populated. A quick check in production before deploying would confirm this: `SELECT count(*) FROM calendarobjects WHERE componenttype='VEVENT' AND firstoccurence IS NULL` should be 0.
- **DAV hard rule:** this changes how the DAV backend reads `calendarobjects`, though not the schema, the connection or the routes. It has to be flagged and approved.
- **Optional, schema change (needs separate approval):** `CREATE INDEX ON calendarobjects (calendarid, componenttype, firstoccurence)`. The existing `calendarobjects_ukey (calendarid, uri)` already narrows to one calendar, so this only matters for very large calendars. Do the code fix first and measure before adding the index.

## 5. Tests for the follow-up job

The existing expansion tests (`app/Dav/Tests/EventOccurrenceServiceTest.php`, `CalendarApiTest`, the all-day boundary tests from `76e77a0`) must stay green. They pin the results. Add:

- an event far outside the window (e.g. a year earlier) plus a VTODO in the same calendar. Neither is returned, and neither is read. To check "not read", assert on a count via a spy on the backend, or assert the candidate URIs if the query gets its own small method.
- an all-day event on the window's first and last day for a user at UTC+14 and one at UTC-12, to show the padding works.

## Follow-up job

One small job: Option B in `AppCalendarsPDO::getExpandedEventsCustom` plus the tests in §5. Then re-run the measurement above. The expected result is constant time in N for a fixed window.
