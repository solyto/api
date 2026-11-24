# Implementation: calendar-ics-file-import

id: amazing
developer: rosalie
date: 2026-11-24

## Summary

Users can upload an `.ics` file and import its events into a new calendar or one of their own calendars (API side; contract in room decision `01M871VQHC2G6TGT49VB4D037E`, details in `01M872BJE7PBQ3BN0JQ4GTM14M`). The file is split per UID and each object is stored raw through the existing DAV backend, so time zones, `RECURRENCE-ID` overrides, `EXDATE`, `VALARM`, attendees and unknown properties survive. Imports are queued and report progress through the existing `GET /calendars/import/state`. Each user can run only one import at a time, CalDAV or file.

## Changes

TASK-1 and TASK-2 are in one commit because they share the import state code.

TASK-1 (import state):
- `app/Dav/DTOs/CalendarImportDTO.php`: adds `source`, `targetInstanceId`, `eventsSkipped` and `updatedAt`. The CalDAV fields are nullable, because a file import has none.
- `app/Dav/Services/CalendarImport.php`: `claim()` (a cache lock plus a running check) is used by the CalDAV `start()` and the file import. `selectCalendars()` returns 409 while an import runs and 404 for a file import's state. An import counts as running only in `calendars`/`events` and only while it saved progress in the last 5 minutes. `getState()` checks the secret only when there is one.
- `app/Api/Calendars/Resources/ImportStateResource.php`: adds `source` and `events_skipped`, plus the `failed` stage in the schema.
- `app/Api/Calendars/Jobs/ImportCalendars.php` (review fix): the CalDAV job now carries the `jobId` it was dispatched for. It only works on its own CalDAV state in `calendars`/`events`, so a job that waited past the stale window can't run a file import's state. It also no longer spins on `failed`. Jobs queued before the deploy have no `jobId` and check only `source`.
- `app/Dav/Exceptions/ImportConflictException.php` (409) and `ImportFileException.php` (422), mapped in `bootstrap/app.php` and listed in `dontReport`.

TASK-2 (file import):
- `POST /api/v1/calendars/import/file` (`routes/api.php`, throttled by the new `calendars-import` limiter of 20 per hour in `AppServiceProvider`).
- `ImportCalendarFileRequest`:
  - `file`: required, `.ics`, at most 10 MB.
  - `calendar_id`: optional; it prohibits `name` and `color`.
  - `name` and `color`: optional.
- `ImportController::importFile`: resolves the target through `CalendarService::getOrFail` (404). It returns 403 for shared or external calendars, starts the import and dispatches `ImportCalendarFile`.
- `CalendarImport::startFile` parses and validates the file before accepting it. A file that won't parse, has no events or has more than 5 000 objects is rejected with 422. For a new calendar it picks the name: the given name, else `X-WR-CALNAME`, else the filename, made unique with ` (n)`. It stores the file on the `local` disk as `calendar-imports/<jobId>.ics` and claims the state.
- `CalendarImport::importFile` runs in the job:
  - creates the new calendar if needed, then re-checks the target;
  - checks each object as sabre checks a CalDAV PUT, then stores it raw with `Events::import()` (new, wraps `AppCalendarsPDO::importEventCustom`);
  - counts objects without a `VEVENT`, without `DTSTART`, or rejected by the checks or the backend as skipped, and logs them to the `dav` channel;
  - always deletes the file and clears the caches at the end, and invalidates shared caches when the calendar is shared.
- Events without a UID get a UUID instead of sabre's splitter's `sha1(microtime())`, which can repeat and merge unrelated events into one object.
- `ImportCalendarFile` job: `tries = 1` (a retry would duplicate events), `timeout = 300`. `failed()` sets the stage to `failed` and deletes the file. The job carries its `jobId`, so a job that waited past the stale window can't run a newer import.
- Tests in `app/Api/Calendars/Tests/CalendarFileImportApiTest.php` (28):
  - new calendar, name fallbacks and numbering, color, existing calendar;
  - another user's calendar (404), shared and external calendars (403);
  - validation, unreadable files, files without events, the event limit;
  - fidelity (TZID, `VTIMEZONE`, `EXDATE`, `RECURRENCE-ID` written before its master, `VALARM`, organizer, attendees, X- properties);
  - skipping invalid objects and VTODOs, events without a UID, a round trip with our own export, attaching a to-do to an imported event;
  - 409 for a second file import and the CalDAV start, stale imports, a CalDAV `select` state not blocking;
  - a stale CalDAV job that finds a file import's state, a CalDAV job that stops on `failed`, and one that runs its own import to the end;
  - failed and timed-out jobs, temp-file cleanup.

No DAV schema or route changes. Writes go through the existing `importEventCustom`. No migrations and no new lang strings: envelope messages aren't shown to users.

`composer test` passes (2 351 tests) and `composer analyse` reports no errors.

TASK-3 (read-only shares, asked for by the human at 15:47):
- `CalendarDTO::$isReadOnly` comes from sabre's `share-access` (`ACCESS_READ`), and `CalendarResource` adds `is_read_only` (additive).
- `CalendarService::assertWritable()` throws `CalendarReadOnlyException` (403) before `createEvent`, `updateEvent`, `destroyEvent`, both occurrence edits, and `moveEvent` (source and target). DAV clients were already stopped by sabre's ACL; the API wrote through the backend directly. The OpenAPI 403 responses are updated.
- Tests (`CalendarApiTest`, "Read-only shared calendars"): the list flag; 403 on create, update, delete and both occurrence routes; moving in and out; a read-write share still works.
- A write that used to succeed on a read-only share now returns 403. That's the intended fix; old apps treat it as a failed save.

TASK-4 (CalDAV import stores events raw, asked for by the human at 15:47):
- `ICalendarHelper::calendarDataFromXml()` returns each REPORT response's raw `calendar-data`. The old `parseEntriesFromXml()`/`parseICalData()` and `EventDTO::fromImport()` had no other callers and are removed.
- `CalendarImport::importEvents()` reads each remote object, splits it per UID (`splitCalendar()`, now shared with the file import, which also drops calendar-level `METHOD`), and stores it with `storeObject()`. Unreadable or rejected objects count toward `events_skipped`.
- Tests: `DavImportTest` covers a Nextcloud-style object with `METHOD:PUBLISH`, TZID, `VTIMEZONE`, `EXDATE`, `RECURRENCE-ID`, `VALARM` and an attendee, stored as-is, plus one invalid and one unreadable object skipped. `ICalendarHelperTest` covers the new extractor. The file import test now includes `METHOD:PUBLISH`.

## Known issues / follow-ups

- `CalendarImportDTO::$stage` has the default `'started' | 'select' | …`, which is a bitwise OR of strings, not a type. This predates the job and was left as is.
- The app could use `is_read_only` to hide the edit actions on read-only shares (told charmaine; app scope).
- `.env.testing` was already modified in the worktree and is left uncommitted, as the human asked.
