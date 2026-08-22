# Tasks: upcoming events erroir

id: slight
status: open
analyst: analyst
date: 2026-08-22

<!-- Produced by @analyst from brief.md. -->

## Findings

Dashboard "Coming up" widget is fed by `GET /api/v1/calendars/events/widget`
(`CalendarController::listWidgetEvents` → `CalendarService::listWidgetEvents`
→ `DavService` → `AppCalendarsPDO::getExpandedEventsCustom`). Two independent
API-side defects can return an event from yesterday:

1. **UTC "today" instead of the user's timezone** —
   `CalendarService::listWidgetEvents()` computes `$today = date('Y-m-d')` and
   `new \DateTime('today')` in the app default timezone, which is UTC
   (`config/app.php` 'timezone' => 'UTC'), while events are created in the
   *user's* timezone (`EventDTO::fromRequest` uses `$user->settings->timezone`).
   For a user in e.g. Europe/Berlin (UTC+2), between local midnight and 02:00
   the API's "today" is still the previous day, so the widget window starts a
   day early and yesterday's events are returned. The codebase already has the
   established pattern `$user->settings->timezone ?? 'UTC'` for exactly this
   (see `SendDailyDayRemindersCommand`, `WeatherController`).

2. **Boundary-equality on exclusive DTEND (all-day events)** — all-day events
   are stored with an exclusive DTEND at next midnight (`EventDTO::toVCal`).
   In `AppCalendarsPDO::getExpandedEventsCustom` the overlap checks use strict
   comparisons (`$endOrig < $start`, `$occEnd < $start`), so an all-day event
   from yesterday whose DTEND equals the window start (both midnight) is *not*
   recognized as fully past and is kept. Per RFC 4791/5545 semantics an event
   ending exactly at the window start does not overlap.

Uncertainties (developer must verify during implementation):

- `vendor/` is absent from this workspace, so sabre/dav's own `calendarQuery`
  time-range pre-filter boundary behavior could not be verified from source.
  If Sabre already pre-filters single events with DTEND == window start, only
  the recurrence branch of finding 2 manifests; the fix should make both
  branches consistent regardless.
- The frontend (separate repo) is assumed to call the widget endpoint for
  "Coming up"; inference is based on route/test naming only.
- The fix must keep genuinely ongoing events (multi-day or timed events that
  started before the window and end inside it) — only exact-boundary ends may
  be excluded.
- NOTE per AGENTS.md: TASK-2 edits a file under `app/Dav/` — it changes DAV
  query code only, NOT the pgsql connection, schema, or DAV routes.

## Task breakdown

TASK-1: Compute the widget window ("today" and the cache-key date) in the user's timezone in `CalendarService::listWidgetEvents`, following the existing `$user->settings->timezone ?? 'UTC'` pattern; the cache key must roll over on the user's local date.
     files: app/Api/Calendars/Services/CalendarService.php
     depends: none
     risk: medium — changes cache-key semantics and shifts the window for every non-UTC user; must not touch `listEvents` (monthly) which is out of scope.

TASK-2: Fix the off-by-one overlap checks in `AppCalendarsPDO::getExpandedEventsCustom` so occurrences ending exactly at the window start (exclusive DTEND of all-day events, both the single-event and the recurrence branch) are excluded, keeping genuinely overlapping/ongoing events; verify behavior against the vendored sabre/dav `calendarQuery` time-range filter.
     files: app/Dav/Backend/AppCalendarsPDO.php
     depends: none
     risk: medium — this backend is shared by the monthly `listEvents` and the widget; a wrong comparison could drop legitimate events, so it needs the TASK-3 regression tests to confirm.

TASK-3: Add Pest regression tests for the widget endpoint: an all-day event from yesterday and a daily-recurring all-day occurrence ending at today 00:00 must NOT appear; today's and tomorrow's events must; include a non-UTC user timezone case (e.g. Europe/Berlin). Extend the existing `it('lists widget events')` in the CalendarApiTest describe block; the DAV backend already works under the test harness (existing store/list event tests pass against SQLite).
     files: app/Api/Calendars/Tests/CalendarApiTest.php
     depends: TASK-1, TASK-2
     risk: low — test-only changes.

TASK-4: Run the focused calendar tests plus the full Pest suite (`composer test`) and `pint` on the changed files; confirm no regressions outside the Calendars module.
     files: none (verification only)
     depends: TASK-3
     risk: low — read-only verification; stop and report if unrelated tests fail.
