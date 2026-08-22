# Implementation: upcoming events erroir

id: slight
status: open
developer: developer
date: 2026-08-22

## Summary

Fixed the dashboard "Coming up" widget showing events from yesterday. Two
API-side defects were confirmed and fixed: the widget window ("today") was
computed in the app's default timezone (UTC) instead of the user's timezone,
and the overlap checks in the DAV backend treated an event ending exactly at
the window start as overlapping (all-day events store an exclusive DTEND at
next midnight, so yesterday's all-day event ends exactly at today 00:00).

## Changes

TASK-1: `app/Api/Calendars/Services/CalendarService.php` —
`listWidgetEvents()` now resolves the user's timezone via the established
`$user->settings->timezone ?? 'UTC'` pattern and computes both the window
boundaries and the cache-key date in that timezone. The cache key rolls over
at the user's local midnight. `listEvents` (monthly) untouched.

TASK-2: `app/Dav/Backend/AppCalendarsPDO.php` — in
`getExpandedEventsCustom()`:
- End-boundary checks changed from strict `<` to `<=` in both the
  single-event branch (`$endOrig < $start`) and the recurrence branch
  (`$occEnd < $start`): an occurrence ending exactly at the window start does
  not overlap it (exclusive end, RFC 4791/5545 — matches sabre's own
  `EventIterator::fastForward` which already uses `<=`).
- All-day events are stored as floating `VALUE=DATE` values (the user's
  local dates), which VObject parses as UTC (`DateTimeParser::parseDate`
  defaults to UTC). For non-UTC users the parsed instants are offset from the
  user's midnight, so a plain `<`→`<=` change would not have fixed them.
  All-day end boundaries are therefore rebased onto the query window's
  wall-clock timezone before comparison. For the monthly `listEvents` path
  (window in UTC) the rebase is a no-op, so no behavior change there.
- All-day events without DTEND now end one day after DTSTART (RFC 5545 §3.6.1;
  matches `EventIterator`'s own 24h default for all-day no-DTEND events).
  Without this, the `<=` change would have dropped such events.

Verified against the vendored sabre/dav (resolves the analyst's
uncertainty): `calendarQuery`'s time-range pre-filter never applies to our
calls — the filter array we build (`time-range` at the top level, empty
`comp-filters`) does not match the `CalendarQueryParser` shape sabre expects,
so sabre returns all URIs unfiltered and the custom loop in
`getExpandedEventsCustom` is the sole time filter. There is no sabre-side
boundary behavior to conflict with. Note: this was read-only verification;
the filter shape itself was left unchanged (out of scope).

TASK-3: `app/Api/Calendars/Tests/CalendarApiTest.php` — extended the
`Calendar events` describe block with two regression tests:
- UTC user: yesterday's all-day event (exclusive DTEND == window start) and
  yesterday's timed evening event must NOT appear; an ongoing multi-day
  event, today's and tomorrow's events must appear.
- Europe/Berlin user: yesterday's all-day event, yesterday's timed evening
  event, and the yesterday occurrence of a daily-recurring all-day event
  must NOT appear; today's events and the today occurrence must appear.
Both tests were verified to fail with the TASK-2 fix reverted and pass with
it applied.

TASK-4: verification — focused Calendars suite: 15 passed (75 assertions).
Full suite (`composer test`): 630 passed, 8 failed (1535 assertions). All 8
failures are pre-existing environmental failures from Docker secrets not
being mounted in this sandbox: 4 × AiService (`OpenAI\Factory::withApiKey()`
null key) and 3 × AuthTest password reset (`DatabaseTokenRepository`
hashKey null — `config/app.php` reads APP_KEY via `DockerSecretHelper`) plus
the forgot-password twin. None touch calendar/DAV code. `vendor/` was
absent and was installed from the existing lockfile (no new packages).

## Follow-up (verdict 26eae95 — "TASK-2 partial")

Addressed the reviewer's blocker: the recurrence branch's all-day rebase is
now two-sided, plus the demanded regression tests.

- `app/Dav/Backend/AppCalendarsPDO.php` — for all-day events the recurrence
  branch now rebases *both* window bounds onto the UTC wall clock
  (`$windowStart` for the `fastForward()` argument and the `while`
  comparand, `$windowEnd` for its upper bound) and rebases `$occStart`
  into the query timezone alongside the existing `$occEnd` rebase, so
  every all-day comparison is wall-clock to wall-clock and independent of
  the offset's sign. Non-all-day paths compare the same instants as
  before (the window variables alias `$start`/`$end`).
- Empirical finding, recorded for accuracy: with the pinned
  sabre/vobject 4.5.8 the reviewer's described mechanism does not
  manifest — `EventIterator::fastForward()` advances while
  `getDtEnd() <= $dateTime` (DtEnd-based), so today's occurrence for an
  America/New_York user was *not* skipped even by the pre-fix code (the
  demanded NY test was run against the unfixed code to verify this: only
  the far-edge assertion failed). What the one-sided rebase actually broke
  was the window-`end` comparand: for negative offsets the occurrence
  starting exactly at the window end (today + 3 days) leaked into the
  widget, while UTC/Berlin users did not get it. The wall-clock rebase
  fixes that leak, makes the boundary behavior identical for users east
  and west of UTC, and stops relying on the DtEnd-based fastForward
  coincidence (the fix would also be correct if fastForward were
  DtStart-based).
- `app/Api/Calendars/Tests/CalendarApiTest.php` — the UTC widget test now
  also stores a daily-recurring all-day event (pins the fastForward
  boundary: yesterday's occurrence excluded, today's kept), and a new
  test covers an America/New_York user: today's occurrence of an all-day
  daily event must appear (yesterday's must not) and the occurrence on
  the window-end wall date must not — the last assertion fails on the
  pre-fix code and passes after it.
- Re-verification: CalendarApiTest 12 passed (77 assertions), Calendars +
  Dav unit suites green, full suite 631 passed / 8 failed — same 8
  pre-existing environmental failures as the baseline, plus exactly the
  one new test.

## Known issues / follow-ups

- `pint` fails on the changed files, but it also fails on untouched files:
  the repo has no pint config and does not follow pint's default preset
  (e.g. `else if` vs `elseif`, aligned assignments). Running pint would
  reformat large unrelated sections, so the changed code was written to
  match the surrounding file style instead. A repo-level pint config would
  be needed first (out of scope).
- The filter array passed to sabre's `calendarQuery` in
  `getExpandedEventsCustom` does not match the `CalendarQueryParser` shape
  (top-level `time-range` instead of nested `comp-filters`), so sabre's SQL
  pre-filtering never engages and every calendar object is loaded and
  filtered in PHP. Harmless for correctness (the loop filters), but a
  performance follow-up could fix the filter shape so sabre can pre-filter
  via `firstoccurence`/`lastoccurence` columns.
- Far-edge boundary: an all-day event starting exactly at the window end
  (+3 days) may still be included by the single-event branch for some
  offsets (`$startOrig > $end` compares the UTC-parsed start against the
  query-tz window end; pre-existing start-boundary behavior, explicitly
  out of scope per the task notes — only exact-boundary ends were to be
  excluded). The recurrence branch's far edge is now consistent across
  offsets (excluded, matching UTC semantics).
- The new widget tests derive dates from the real clock (the service uses
  `new DateTime('today', $tz)`, which cannot be frozen via
  `Carbon::setTestNow`). Theoretical sub-millisecond flake if the test runs
  exactly across local midnight.
