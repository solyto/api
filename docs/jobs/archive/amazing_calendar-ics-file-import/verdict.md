# Verdict: calendar-ics-file-import

id: amazing
reviewer: junior
date: 2026-11-24

## Review

Diff checked: `git diff main...HEAD` (17 files). It matches `implementation.md`, and nothing changed outside the planned scope. I checked it against the contract in room decisions `01M871VQHC2G6TGT49VB4D037E` and `01M872BJE7PBQ3BN0JQ4GTM14M`, against `docs/STANDARDS.md` and against `docs/TESTING.md`.

- `composer test`: exit 0, 2 351 tests (re-run alone at `07900d9`). Every test shows the same warning: `/workspace/.env` is missing from the worktree. That comes from the environment, not from this change.
- `composer analyse`: exit 0, no errors (re-run at `07900d9`).

TASK-1: PASS
notes: Re-reviewed after `64fccf3`. The first review found that a stale CalDAV `ImportCalendars` job would run a file import's state (reproduced), and that its loop spun for up to 30 minutes on `failed`. Both are fixed:
- `ImportCalendars` now carries the `jobId` it was dispatched for (`ImportController::selectCalendars`).
- Its loop runs only while `ownsState()` holds: `source === 'caldav'`, the same `jobId` (or a null `jobId` for jobs queued before the deploy), and stage `calendars`/`events`.
- `start()` sets `jobId` (`CalendarImport.php:67`), so the guard always has a value to compare.
- Three new tests cover the stale CalDAV job (with and without a `jobId`), the stop on `failed`, and a normal run to `finished`.

The 409 guard, the `failed` stage, `source` and `events_skipped` match the contract.

TASK-2: PASS
notes: The endpoint, request, 403/404/409/422/429 responses and the 200 response with the initial state match the contract and the follow-up decision. OpenAPI follows rules 2.1/2.3/2.4: the 422 is `oneOf` `ValidationErrorResponse`/`ErrorResponse`. The job sets `tries = 1` and `timeout = 300` (below `retry_after` 360) and gives the reason (rule 9.2). It carries ids only (9.1). The `catch (\Exception)` in `storeObject` has a why-comment (4.8). `Cache::lock` is allowed by rule 8.1. The new exceptions are mapped centrally (7.2). There are no new lang strings, which matches the decision. There are no DAV schema or route changes: writes go through the existing `importEventCustom`. The tests cover fidelity, limits, ownership, round trip, attachments, concurrency and failure.

TASK-3: PASS
notes: Read-only shares (`80317ff`). This task was added to `tasks.md` after the first review, because the human asked for it at 15:47. I didn't see that message myself.
- `CalendarDTO::$isReadOnly` comes from `share-access === Sabre ACCESS_READ` (2). That matches how the backend stores shares (`AppCalendarsPDO.php:908`: 3 = read-write, 2 = read).
- `CalendarService::assertWritable()` covers every API event write. The only `events()->create/update/delete/*Occurrence` callers outside the import are in `CalendarService`, and they are all guarded, including both sides of a move.
- `CalendarReadOnlyException` follows the `CalendarNotFoundException` pattern (an `HttpException` rendered centrally, rule 7.2).
- `is_read_only` is additive, and the schema and `toArray()` agree (rule 2.6). The new 403s are documented.
- Under rule 13.2, a write that used to succeed now returns 403. The human asked for this and `implementation.md` says so.
- Tests cover the flag, all five routes, moves in and out, and the read-write control case.

TASK-4: PASS
notes: The CalDAV import stores events raw (`07900d9`). It was also added after the first review, at the human's request.
- `calendarDataFromXml()` returns each response's raw `calendar-data`, with `LIBXML_NONET`. It no longer logs the response body, which the old `Log::error` did.
- `importEvents` splits each object with the shared `splitCalendar()` and stores it through `storeObject()` (the CalDAV PUT checks). An unreadable entry counts as skipped.
- `splitCalendar()` builds fresh `VCALENDAR`s, so `METHOD` is dropped.
- `parseEntriesFromXml`/`parseICalData`/`EventDTO::fromImport` (the calendar version) have no callers left.
- `DavImportTest` proves fidelity (TZID, `VTIMEZONE`, `EXDATE`, `RECURRENCE-ID`, `VALARM`, attendee, no `METHOD`) and the skip counting.

## Security

Not run separately. From this review: the temp file path comes from a server-generated UUID, so the client filename is never used as a path. The target calendar is resolved through `CalendarService::getOrFail` (user-scoped), and shared/external calendars are refused before and again inside the job. The upload is limited to 10 MB / 5 000 objects and rate limited to 20 per hour. No findings.

## Overall

APPROVED

The blocker from the first review is fixed in `64fccf3`. TASK-3 (`80317ff`) and TASK-4 (`07900d9`), both added later at the human's request, pass review. At `07900d9`, `composer test` (2 351) and `composer analyse` both exit 0. Nothing blocks the merge.
