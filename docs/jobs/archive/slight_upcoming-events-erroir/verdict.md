# Verdict: upcoming events erroir

id: slight
status: open
reviewer: reviewer
date: 2026-08-22

## Review (follow-up round)

Re-reviewed `git diff main...HEAD` after the follow-up commits (fd79e82,
dfabd59, 1631a26) addressing the previous verdict's blocker. Reviewed
CalendarService::listWidgetEvents, AppCalendarsPDO::getExpandedEventsCustom
(single-event and recurrence branches), and all three new widget tests,
cross-checked against EventDTO::toVCal/parseVCal (storage format), StoreEventRequest,
EventResource, routes, and composer.lock. vendor/ is absent again in this
session and bash is restricted to git, so the suite could not be re-run here;
sabre behavior was verified against the pinned sabre/vobject 4.5.8
(composer.lock, untouched on this branch) and independent timezone traces of
both code states (UTC, Europe/Berlin, America/New_York).

TASK-1: PASS
notes: app/Api/Calendars/Services/CalendarService.php:96-117 — unchanged from
the first round and still correct: window boundaries and the cache-key date in
`$user->settings->timezone ?? 'UTC'` (pattern confirmed in
SendDailyDayRemindersCommand / SendDailyCheckInRemindersCommand; settings row
guaranteed by UserObserver::created, `timezone` is fillable). Cache key
`[..., 'widget', $today]` rolls over at the user's local midnight.
`listEvents` (monthly) untouched.

TASK-2: PASS
notes: app/Dav/Backend/AppCalendarsPDO.php:246-321. The previous blocker is
fixed. Single-event branch (unchanged this round, re-verified): `$endOrig <=
$start` with all-day ends rebased onto the query window's wall clock, plus the
RFC 5545 §3.6.1 one-day default for all-day events without DTEND — verified
correct by trace for UTC (boundary equality excluded), Berlin (+2: yesterday's
all-day DTEND == local window start → excluded) and NY. Recurrence branch
(dfabd59): both window bounds are now rebased onto the UTC wall clock for
all-day events (`$windowStart` for fastForward, `$windowEnd` for the while
condition) and `$occStart` is rebased into the query timezone alongside
`$occEnd`, so every all-day comparison is wall-clock to wall-clock, independent
of the offset's sign. Non-all-day paths alias `$start`/`$end`, preserving
instant semantics; only the intended `<`→`<=` end-boundary tightening applies.
`$queryTz` is defined before every use. The developer's empirical finding about
fastForward being DtEnd-based matches sabre/vobject 4.x; their re-analysis of
the actual pre-fix NY defect (window-end comparand leaking the far-edge
occurrence) is consistent with my independent trace of the pre-fix code, and
the fix removes the offset asymmetry on both edges of the recurrence window.
Monthly listEvents path is unaffected (UTC window → rebase is a no-op).

TASK-3: PASS
notes: app/Api/Calendars/Tests/CalendarApiTest.php:120-271 — now three tests,
matching the real request/resource shapes (StoreEventRequest fields,
EventResource `original_start_date` as `Y-m-d\TH:i:s`, widget route
routes/api.php:168): UTC user (yesterday all-day and timed excluded, ongoing
multi-day/today/tomorrow kept, daily-recurring boundary pinned), Europe/Berlin
(all-day + timed yesterday excluded, today kept, recurring occurrence
boundary), and the previously demanded America/New_York negative-offset case
(today's recurring all-day occurrence kept, yesterday's and the window-end
occurrence excluded — the far-edge assertion is the regression test for this
round's fix). Assertions match my traces of the fixed code.

TASK-4: PASS
notes: developer-run verification reported in implementation.md: CalendarApiTest
12 passed (77 assertions), Calendars + Dav unit suites green, full suite
631 passed / 8 failed — arithmetic consistent with the previous round
(630/8 + exactly one new test), and the 8 failures are the same
Docker-secret/APP_KEY environmental ones previously accepted as pre-existing.
Could not be re-run in this review session (vendor absent, bash restricted to
git); static analysis supports the claims. Pint situation unchanged and
previously accepted (no repo pint config; changed code matches file style).

Commit discipline: good — original per-task commits (4611553, a1b7818, 3ed9ee1)
plus follow-up commits correctly reusing the task IDs (dfabd59 TASK-2,
fd79e82 TASK-3), implementation.md has its own commits. Scope: cumulative
branch diff is exactly the three task files plus job docs; composer.json/lock
untouched; pgsql connection, schema, and DAV routes untouched (AppCalendarsPDO
change is query logic only, as flagged in tasks.md per AGENTS.md); no secrets
committed (`.env.testing` is not in the branch diff and was not inspected).

Housekeeping (non-blocking, unchanged from the previous round): the filled-in
tasks.md (Findings + task breakdown) still exists only as an uncommitted
working-tree change — the committed copy carries only the frontmatter fill;
root AGENTS.md and .env.testing also carry uncommitted local (sandbox/mount)
modifications, left as-is.

## Security

none — no routes, auth, or secrets handling changed; nothing secret-bearing is
committed.

## Overall

APPROVED

The reported bug (an event from yesterday under "Coming up") is fixed for UTC,
positive-offset, and negative-offset users: the widget window is computed in
the user's timezone with a cache key that rolls over at local midnight, and
exact-boundary ends are excluded in both the single-event and recurrence
branches with wall-clock-consistent comparisons. The previous verdict's sole
blocker is resolved with the demanded regression tests. No merge blockers.
