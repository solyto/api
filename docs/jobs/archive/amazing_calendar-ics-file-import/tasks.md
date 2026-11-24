# Tasks: calendar-ics-file-import

id: amazing
analyst: rosalie (from the room)
date: 2026-11-24

<!-- Produced by @analyst from brief.md. -->

## Task breakdown

TASK-1: Import state: one import per user (409 on the CalDAV start/select while one runs), `failed` stage, `source` and `events_skipped` in the state.
files: app/Dav/Services/CalendarImport.php, app/Dav/DTOs/CalendarImportDTO.php, app/Api/Calendars/Resources/ImportStateResource.php, app/Dav/Exceptions, bootstrap/app.php, tests
depends: none
risk: medium — touches the existing CalDAV import's state handling

TASK-2: `POST /calendars/import/file`: request, controller action with OpenAPI, service (split per UID, raw store), queued job (no retries), rate limiter, tests (fidelity, limits, ownership, round trip, attachments).
files: routes/api.php, app/Api/Calendars/{Controllers,Requests,Jobs,Tests}, app/Dav/Services/{CalendarImport,Events}.php, app/Shared/Providers/AppServiceProvider.php
depends: TASK-1
risk: medium — writes raw client data into DAV storage (through the existing backend method, no schema change)

TASK-3: Refuse API event writes on calendars shared read-only (create, update, move in/out, delete, occurrence edits) with 403; expose `is_read_only` in the calendar list. (Human, 15:47: "fix it right now".)
files: app/Api/Calendars/Services/CalendarService.php, app/Api/Calendars/Exceptions, app/Api/Calendars/Controllers/CalendarController.php, app/Api/Calendars/Resources/CalendarResource.php, app/Dav/DTOs/CalendarDTO.php, tests
depends: none
risk: medium — a write that used to succeed on a read-only share now returns 403 (intended)

TASK-4: The CalDAV account import stores each remote object raw (same path as the file import) instead of parsing and rebuilding it. (Human, 15:47: "fix it here".)
files: app/Dav/Services/CalendarImport.php, app/Dav/Helpers/ICalendarHelper.php, app/Dav/Tests/DavImportTest.php
depends: TASK-2
risk: medium — changes what the existing CalDAV import writes
