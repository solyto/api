# Implementation: Report: VTODO

id: scope
developer: @developer
date: 2026-10-14

## Summary

This was a report-only job. I wrote `docs/reports/vtodo-caldav-sync-2026-10-14.md`, which answers whether and how solyto can offer CalDAV sync of todos as VTODO. Short answer: yes. The recommendation is a virtual, VTODO-only collection served from MariaDB through a custom sabre backend that writes via `TodoService`, built in phases with read-only first. No application code, migrations, config, routes or DAV wiring were changed.

Two of the analyst's facts turned out wrong when checked against the code, and the report corrects both:

1. `TodoService` is the only write path for todos. `IntegrationGateway` and `SolytoBot` delegate through `QuickAddService`, and `EventAttachmentService` only writes `calendar_event_todos` link rows.
2. The hardcoded `'components' => 'VEVENT'` in `AppCalendarsPDO::createCalendarCustom` restricts nothing. Sabre ignores the key, so app-created calendars are stored as `VEVENT,VTODO` and already accept VTODOs today.

## Changes

- TASK-1: Report §1 "Current state". Checks the todo model, migrations, write paths, the recurrence model, the DAV server wiring, `AppCalendarsPDO`, `EventDTO`, `ICalendarHelper` and `CacheInvalidationPlugin` against the code, with file:line references. Includes the two corrections above, and the finding that VTODOs already stored in pgsql would leak into `getEventsCustom` and the calendar export (from reading the code; not tested).
- TASK-2: Report §2. Field-by-field mapping table from the solyto todo to RFC 5545 VTODO, graded lossless / lossy / unmappable, plus a section on recurrence.
- TASK-3: Report §3. Options A (virtual MariaDB backend), B (mirror into pgsql), C (pgsql as source of truth) and D (ICS feed), each covering sync tokens / ETags, sharing, ACL, `CacheInvalidationPlugin`, the ⚑ flags for pgsql and DAV changes, and S/M/L/XL effort. Ends with a comparison table.
- TASK-4: Report §4. RFC 4791, 5545, 6578 and 9253 requirements with section citations, VTODO-only vs mixed collections, and a client table. Client claims that couldn't be confirmed from a primary source are marked unverified.
- TASK-5: Report §5. Recommendation (option A, phases 0 to 3), eight risks and twelve open questions for a human.
- TASK-6: Wrote the report summary in the same style as the existing `docs/reports/*`, and this file.

Files: `docs/reports/vtodo-caldav-sync-2026-10-14.md` (new) and `docs/jobs/scope_report-vtodo/implementation.md`.

## Known issues / follow-ups

- `vendor/` isn't installed in this sandbox. I read the sabre sources from the upstream GitHub tags that `composer.lock` pins (dav 4.7.0, vobject 4.5.8). Nothing was installed and nothing ran against a live DAV server.
- Client behaviour (Apple Reminders, Thunderbird, Evolution, tasks.org, jtx Board) is mostly unverified and needs real-device testing before phase 2.
- I couldn't check how many VTODOs already exist in production pgsql calendars. Phase 0 of the report depends on that count.
- I couldn't tell whether `/.well-known/caldav` is handled by the reverse proxy, since it isn't in this repo.
- `.env.testing` shows as modified in `git status`. It is a `/dev/null` character-device mount in this sandbox, not a change, and git can't stage it, so it stays "modified".
