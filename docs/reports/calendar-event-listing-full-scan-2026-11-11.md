# Report: Calendar event listing reads every event the calendar has ever had

date: 2026-11-11
branch: `main`, tree at `e249b72`
scope: Performance of `AppCalendarsPDO::getExpandedEventsCustom`, the one function behind every API event listing (month view, widget, "today", daily reminders, cache refresh). Written for the maintainer. No application code, config, migrations or DAV wiring were changed. A throwaway probe test was run and deleted.
read: false

## Summary

**Every event listing loads, queries one by one, and parses every object in the calendar, whatever the requested window is.** The time-range filter is handed to sabre at the wrong nesting level, so sabre ignores it and returns every URI in the calendar (§1). Then each URI costs one more SQL round trip plus a full `VObject` parse (§2). A user with 3 000 events across 5 years pays about 3 000 queries and 3 000 parses for the widget's 3-day window. Each listing call does this once per calendar.

The cost grows with the calendar's **history**, not with what is on screen. It never goes down, because past events are never deleted.

**This was spotted before but never followed up.** The archived job `slight_upcoming-events-erroir` (`implementation.md:119-125`) noted the wrong filter shape as "a performance follow-up" and left it out of scope. No report or backlog entry tracks it.

**Recommendation:** replace the `calendarQuery` + per-URI `getCalendarObject` pair with a single query of our own. It should filter on sabre's denormalised `firstoccurence`/`lastoccurence` columns and widen the window by one day on each side (§3, option B). The existing PHP filtering stays as the exact boundary check, so the change is safe. It needs no schema change. An index is an optional second step and touches the DAV `pgsql` schema (⚑, §3.3).

## 1. The filter is ignored

`app/Dav/Backend/AppCalendarsPDO.php:240-253` builds:

```php
['name' => 'VEVENT', 'prop-filters' => [], 'comp-filters' => [], 'time-range' => [...]]
```

sabre's `CalDAV\Backend\PDO::calendarQuery` (`vendor/sabre/dav/lib/CalDAV/Backend/PDO.php:762-839`) only reads the component type and time range from `comp-filters[0]`, as the `VCALENDAR > VEVENT` shape from `CalendarQueryParser` has them. With `comp-filters` empty:

- `$requirePostFilter = false` (`:774-776`)
- `$componentType` and `$timeRange` stay `null`

So the SQL is just `SELECT uri FROM calendarobjects WHERE calendarid = :calendarid`.

**Measured** (throwaway Pest probe, SQLite test DB): 200 one-hour events in 2020, query window 2030-10-01 → 2030-10-04.

| filter shape | URIs returned |
|---|---|
| as used today (top-level `time-range`) | **200** |
| nested under `comp-filters[0]` (sabre's shape) | 0 |

The stored rows do carry correct `firstoccurence`/`lastoccurence` values, for example `1577872800`/`1577876400`. sabre fills them on every `createCalendarObject`/`updateCalendarObject`, so the data needed to filter in SQL is already there.

## 2. What each listing costs today

For each calendar in the listing, `getExpandedEventsCustom` (`:255-268`) does:

1. 1 query returning all N URIs
2. N × `getCalendarObject()`: one `SELECT … calendardata …` round trip per object
3. N × `Sabre\VObject\Reader::read()`, then a check of the result against the window in PHP

Callers (all through `CalendarService`):

| caller | window | cache TTL | trigger |
|---|---|---|---|
| `listEvents` (`CalendarService.php:88-110`) | month ± 3 months | 15 min (current month), 1 h (future), 7 d (past) | `GET calendars/events`, every month the app navigates to |
| `listWidgetEvents` (`:114-134`) | 3 days | 1 h | `GET calendars/events/widget`, `GET widgets/your-day` |
| `listTodayEvents` (`:139-159`) | 1 day | 15 min | dashboard via `IntegrationGateway`, and `SendDailyDayRemindersCommand` (every user, daily) |
| `RefreshCalendarCache` job | current month | n/a | dispatched by `CacheInvalidationPlugin.php:127,149` on **every** DAV write |

The caches hide some of this, but the TTLs are short and every write clears them. The last row is the worst case:

- A DAV client doing a first sync, or re-uploading a calendar, sends one `PUT` per event.
- Each `PUT` dispatches a `RefreshCalendarCache` job, and the job is not unique.
- Each job then rescans **all** objects in all of the user's calendars.

Uploading N events therefore costs about N²/2 object reads and parses on the queue. For 1 000 events that is about 500 000 reads and parses.

## 3. Options

### A. Fix the filter shape only

Pass the nested shape to `calendarQuery`:

```php
['name' => 'VCALENDAR', 'is-not-defined' => false, 'prop-filters' => [], 'time-range' => null,
 'comp-filters' => [['name' => 'VEVENT', 'is-not-defined' => false, 'comp-filters' => [], 'prop-filters' => [], 'time-range' => [...]]]]
```

- **Pro:** a few lines, and it stays on sabre's API.
- **Con:** with both `start` and `end` set, sabre keeps `$requirePostFilter = true` (`PDO.php:793-797`). It then selects `calendardata` and parses each matching row with `validateFilterForObject`. Our loop then fetches and parses the same rows **again**. So the N+1 queries and the double parse stay, just on fewer rows.
- **Risk:** sabre's post-filter compares in absolute time. All-day and floating events are stored as UTC wall clock, while our window is in the user's timezone (see the rebase logic at `:276-308`). sabre could drop an all-day event that our own loop would keep. The widget and today paths for non-UTC users are exactly where the archived job fixed boundary bugs.

### B. One custom query (recommended)

Replace `:250-268` with one query on the backend's own PDO, the same way `getEventByUriCustom` (`:380`) already does:

```sql
SELECT id, uri, etag, calendardata FROM calendarobjects
WHERE calendarid = ? AND componenttype = 'VEVENT'
  AND lastoccurence > ? AND firstoccurence < ?
```

Bind `$start - 1 day` and `$end + 1 day` as timestamps.

- The one-day margin covers the up-to-±14 h offset between floating/all-day values (stored as UTC wall clock) and the user-timezone window. The existing PHP checks stay the exact boundary. SQL only needs to return a superset, so the boundary behaviour fixed and tested in `slight_upcoming-events-erroir` does not change.
- Recurring events are still found: for an infinite `RRULE` sabre stores `lastoccurence` = `MAX_DATE` (2038-01-01, `PDO.php:34,654-656`). One catch: a window after 2038 would miss infinite series. That is far away, but the implementation can treat `lastoccurence >= MAX_DATE` as unbounded.
- Cost becomes 1 query and about as many parses as events in or near the window.
- **Edge case to decide:** filtering on `componenttype = 'VEVENT'` skips objects whose *first* component is a `VTODO`, even if a later component is a `VEVENT`. Clients don't produce such objects in practice. Dropping the `componenttype` condition is also fine: VTODO rows have `NULL` occurrence columns and drop out of the range condition anyway. That NULL behaviour should be checked once in the implementation job.
- **Tests that already guard this:** `CalendarApiTest` (the boundary regression tests), `EventOccurrenceServiceTest`, `DavClientFixtureTest`. Add one test asserting that an event far outside the window is never parsed or returned.

### C. Also deduplicate `RefreshCalendarCache` (independent, cheap)

Make the job `ShouldBeUnique` per `userId` with a short `uniqueFor` (e.g. 60 s), or dispatch it with a small delay. A burst of `PUT`s from a syncing client then causes one rebuild instead of one per event. This is worth doing even after B, because B makes each run cheap but not free.

### 3.3 Optional: index (⚑ DAV `pgsql` schema)

`calendarobjects` only has `calendarobjects_ukey (calendarid, uri)` (`database/migrations/2025_12_08_161442_create_dav_tables.php:120`). With B, Postgres still reads all of the calendar's rows through that index and filters them. That is cheap compared to today's N round trips plus parses, so **B alone is the big win**.

If calendars grow very large, an additional index such as `(calendarid, firstoccurence, lastoccurence)` would help. It is additive and sabre itself doesn't care, but it touches the DAV schema, so it needs the maintainer's sign-off per the project rules.

## 4. Suggested follow-up job

1. Option B in `AppCalendarsPDO::getExpandedEventsCustom`, plus one "far outside the window" test.
2. Option C on `RefreshCalendarCache`.
3. The index only if production `calendarobjects` counts per calendar justify it (decision ⚑).

`composer test` and `composer analyse` must pass. No migration is needed for 1 and 2.
