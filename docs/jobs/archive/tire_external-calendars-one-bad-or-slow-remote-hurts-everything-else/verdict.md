# Verdict: external calendars — one bad or slow remote hurts everything else

id: tire
reviewer: reviewer
date: 2026-11-14

## Review

Reviewed `git diff main...HEAD` on `feature/tire_external-calendars-one-bad-or-slow-remote-hurts-everything-else`. Every change in the diff maps to a task; nothing out of scope.

TASK-1: PASS
notes: `tests/Support/FakeCalDavServer.php` — `failDelete` answers DELETE with 503, other methods untouched.

TASK-2: PASS
notes: `AppCalendarsPDO::remoteState()` now falls through to the shared `store(... fetched_at => time() ...)` on `ExternalCalendarUnavailableException`, keeping the last known state (or null ctag/sync token). `markStateStale()` still sets `fetched_at => 0`, so writes still force a refetch. Docblock updated. Test "asks a dead remote for its state at most once per TTL" ages the entry, takes the remote down, issues two PROPFINDs, and asserts one remote request and the last known ctag.

TASK-3: PASS
notes: The per-object body moved verbatim into `expandObject()` (the inner `continue`/`break` semantics are unchanged, since they apply to the same VEVENT foreach/while loops). It is wrapped in a `\Throwable` catch, and a missing DTSTART throws so the object is skipped. The log carries only calendar id, uri and exception class, with no calendar data. Test covers garbage, a missing DTSTART and a valid object → 200 with only "Good".

TASK-4: PASS
notes: `calendarQuery()` external branch guards `validateFilterForObject()` through the same `logSkippedObject()`. Skipped objects are neither in `$uris` nor prefetched. The REPORT test asserts 207 with the single valid href.

TASK-5: PASS
notes: Scoped option as recommended. External results are stored as `['events' => [...]]`, and other shapes count as a miss. `UserCacheService` is unchanged. Invalidation is by `forgetByPrefix`, so key format compatibility with other code is unaffected. Test asserts one REPORT across two widget loads of an empty window.

TASK-6: PASS
notes: Create-first is kept. On a delete throw, `removeMovedCopy()` deletes the target copy (logging and swallowing its own failure) and the original exception is rethrown (API 503). All four invalidation lines are in `finally`. The test asserts no native copy, the remote still holds the source, and both prefixes were invalidated. The native `false` return case stays an open question in tasks.md, outside the brief.

TASK-7: PASS
notes: The reviewer ran the full suite. `composer test` exit 0 (1532 tests). Every test shows a "warning" only because the sandbox has no `/workspace/.env` (phpdotenv `file_get_contents` warning in `tests/TestCase.php:21`), which is environmental and not caused by this job. `composer analyse` exit 0, no errors. The uncommitted `.env.testing` entry in `git status` is a `/dev/null` character-device mount from the sandbox, not a job change.

## Security

Not run separately. The log lines deliberately exclude calendar data and exception messages. No secrets are touched.

## Overall

APPROVED

No blockers.
