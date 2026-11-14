# Tasks: investigate — time-range query can drop recurring series

id: ride
analyst: analyst
date: 2026-11-14

## Findings from the desk check

Checked against the locked versions (sabre/dav 4.7.1, sabre/vobject 4.6.1).
`vendor/` is not installed in this worktree, so the sabre source was read
upstream at tag 4.7.1.

- `Sabre\CalDAV\Backend\PDO::getDenormalizedData()` is **protected**. Its
  component loop takes the first non-VTIMEZONE component and `break`s. It
  then reads `DTSTART` from that component and checks `isset($component->RRULE)`
  on the same one. When the first component is an override (RECURRENCE-ID, no
  RRULE), the object is handled as a single event: `firstoccurence` is the
  override's DTSTART and `lastoccurence` is its DTEND. Brief item 1 is
  therefore confirmed in the code. TASK-1 still has to show it in a test.
- `createCalendarObject`/`updateCalendarObject` store the original
  `$calendarData` string. They take `etag` (`md5`) and `size` from
  `getDenormalizedData()`'s return value.
- `MAX_DATE = '2038-01-01'`. Infinite series get exactly `2145916800`. A
  finite series that runs past it stops at the first occurrence ending at or
  after that date, so its value is `>= 2145916800`.
- Sabre's own CalDAV `calendar-query` REPORT filters on the same columns. So
  today, DAV clients doing time-range queries also miss these series. Fixing
  the stored bounds fixes both the app and the DAV REPORT.
- Upstream: [sabre-io/dav#1512](https://github.com/sabre-io/dav/issues/1512)
  is still open. It is about the neighbouring problem that only the first
  VEVENT is considered. Nextcloud's `CalDavBackend::getDenormalizedData` works
  around it by picking the base component (`getBaseComponent()`) instead of
  the first one, so the order does occur in practice.
- Real client order (Thunderbird, Apple Calendar, DAVx⁵) cannot be verified
  from this container. RFC 5545 does not require the master to come first, so
  the fix is warranted whatever those three clients do.

## Fix chosen (from the brief's options)

Recompute the bounds from the master on write, by overriding
`getDenormalizedData()` in `AppCalendarsPDO`. The stored body is left alone.

The other options were rejected:
- **Reordering on write** changes the bytes a client PUT. The ETag then no
  longer matches the client's copy, which forces re-downloads and
  If-Match/412 churn.
- **An RRULE marker column** is a `pgsql` schema change, which needs separate
  sign-off.

The override needs no schema change and only changes the values sabre
already writes into existing columns. ⚑ It does change DAV-layer behaviour
(what sabre's REPORT returns), so it is flagged here per the hard rules.

## Task breakdown

TASK-1: Add a Pest regression test that PUTs a recurring object over DAV with the override VEVENT before the master, then asserts that the series is listed in a window far from the override (expected to fail before TASK-2).
  files: app/Dav/Tests/EventTimeRangeQueryTest.php
  depends: none
  risk: low — test only. Reuses the existing `rangePut`/`davIcs`/`rangeTitles` helpers and the `pgsql` test connection.
  notes:
  - Fixture: master `RRULE:FREQ=WEEKLY` (infinite) starting in 2030-01. One override with `RECURRENCE-ID` at an early occurrence comes **first** in the VCALENDAR, and the master second.
  - Assert three things: `getEventObjectsInRange` returns the URI for the existing 2030-10-14..17 window (or a month without the override); `listExpanded` returns the series title there; and the stored `firstoccurence`/`lastoccurence` (read via `DB::connection('pgsql')->table('calendarobjects')`) equal the master's DTSTART and `2145916800`.
  - Add a master-first twin to show both orders give the same bounds.
  - Run it before TASK-2 and record the failing output in implementation.md (the reproduction the brief asks for). **If it does not fail, stop and report. Do not do TASK-2–4.**

TASK-2: Override `getDenormalizedData()` in `AppCalendarsPDO` so that a VEVENT object whose first non-VTIMEZONE component has a RECURRENCE-ID gets `firstOccurence`/`lastOccurence` from its master (the VEVENT without RECURRENCE-ID). Everything else comes from `parent::` on the original data.
  files: app/Dav/Backend/AppCalendarsPDO.php
  depends: TASK-1
  risk: medium — it runs on every native calendar write (app and DAV clients), and a wrong bound silently hides events. The parent stays the source of etag/size/uid/componentType, which limits the blast radius.
  notes:
  - Call `parent::getDenormalizedData($calendarData)` first. Only when the condition holds, parse the data, move the master to the front of a clone, call parent on that serialisation, and take **only** the two occurrence keys from it.
  - `etag`/`size` must stay computed from the original string, because the stored body is the original.
  - Leave alone: master-first objects, objects with no master (overrides only), and VTODO/VJOURNAL.
  - Do not widen the bounds with min/max over the overrides. That is sabre#1512 (an override moved earlier than the master), a separate known limitation already noted in the `as_` job, and out of scope here.
  - External calendars never reach this (they have no local rows). Add a one-line docblock explaining why the override exists.

TASK-3: Add a dry-run-by-default artisan command that recomputes `firstoccurence`/`lastoccurence` for existing VEVENT rows whose data has an override before the master. With `--apply` it updates only those two columns.
  files: app/Api/Calendars/Commands/RecomputeEventOccurrenceBounds.php (new), bootstrap/app.php (register `app/Api/Calendars/Commands` in `withCommands`), app/Dav/Tests/ or app/Api/Calendars/Tests/ (command test)
  depends: TASK-2
  risk: medium — it writes `pgsql` DAV rows (data only, no schema), so it must be flagged and must not be run by the agent against any real database.
  notes:
  - Model it on `App\Api\Contacts\Commands\RepairCorruptedVCards` (`--apply` flag, `chunkById` over `DB::connection('pgsql')`, progress bar, summary).
  - Compute the bounds with `AppCalendarsPDO::getDenormalizedData()`. It is protected, so add a narrow public wrapper only if needed, or instantiate the backend and use a public method. Do not duplicate sabre's logic.
  - Leave `calendardata`, `etag`, `size`, `lastmodified` and `calendarchanges` untouched. The body is unchanged, so clients need no sync.
  - Test: insert an override-first row with wrong bounds directly. Then check that the dry run changes nothing and `--apply` fixes it.
  - ⚑ Scope question for the maintainer: the brief does not mention existing rows. Without this command, affected series stay hidden until the client writes them again. If the maintainer does not want a command, drop this task and list the stale rows as a known issue instead.

TASK-4: Treat `lastoccurence >= 2145916800` (sabre's `MAX_DATE` 2038-01-01 UTC) as unbounded in `getEventObjectsInRange`, and add a far-future window test.
  files: app/Dav/Backend/AppCalendarsPDO.php, app/Dav/Tests/EventTimeRangeQueryTest.php
  depends: none (TASK-1 touches the same test file, so do it after TASK-1 to avoid conflicts)
  risk: low — a single extra OR clause on the native SQL path, and the PHP occurrence loop is still the exact filter.
  notes:
  - Change the clause to `(lastoccurence > ? OR lastoccurence >= ?)` with the cap as a named private const that has a one-line comment pointing at sabre's `MAX_DATE`.
  - Test: an infinite daily series and a finite one ending in 2040 are both listed for a 2039 window, and a series ending in 2031 is not.
  - Sabre's own DAV REPORT keeps the cap. That is upstream behaviour and is not changed here.
  - Decision: the brief leans towards "accept and document", but this fix is one clause and removes a silent drop, so it was preferred. If the maintainer prefers accept-and-document, replace this task with a docblock note on `getEventObjectsInRange`.

TASK-5: Close out the backlog entry and record the results.
  files: docs/backlog/calendar-time-range-override-first.md (remove, or mark done per the manigot backlog convention), docs/jobs/ride_investigate-time-range-query-can-drop-recurring-series/implementation.md
  depends: TASK-1, TASK-2, TASK-3, TASK-4
  risk: low — docs only.
  notes:
  - implementation.md must cover: the TASK-1 failing-then-passing output; the ⚑ DAV behaviour flag (sabre REPORT results change for affected objects); the TASK-3 production run (dry run first, output shown to the maintainer before `--apply`); that real-client ordering was not verified live; and sabre#1512 (an override moved earlier than the master) as a still-open known limitation.
  - `composer test` and `composer analyse` must both exit 0.

## Unclear / for the maintainer

- TASK-3 (backfill command) is beyond the brief's literal scope. It is included because without it the fix only helps objects written after deploy.
- TASK-4 goes against the brief's "probably accept" default, for the reason given in its notes. Either task can be dropped without affecting the others.
