# Verdict: Report: VTODO

id: scope
reviewer: @reviewer
date: 2026-10-14

## Review

Branch `feature/scope_report-vtodo` verified. Diff `main...HEAD` touches only
`docs/reports/vtodo-caldav-sync-2026-10-14.md` (new) and the job files under
`docs/jobs/scope_report-vtodo/`. No application code, migrations, config,
routes or DAV wiring changed, which matches the report-only scope.

TASK-1: PASS
notes: Spot-checked against the code and all claims hold:
- `DavServerFactory.php:31-34` uses stock `Sabre\CalDAV\Backend\PDO` on pgsql, with the Sync plugin and no ICS export plugin.
- `AppCalendarsPDO::createCalendarCustom` passes a bare `'components' => 'VEVENT'` key. Sabre's `createCalendar` only reads the `supported-calendar-component-set` property and otherwise defaults to `VEVENT,VTODO`, so the correction is right.
- `getEventsCustom` does no `componenttype` filtering, while `getExpandedEventsCustom` filters on VEVENT.
- Single write path: `QuickAddService::commitTodo` → `TodoService`, `IntegrationGateway::commit` → `QuickAddService`, and `EventAttachmentService` writes only `CalendarEventTodo` rows.
- `spawnNextOccurrence` creates a new row and skips `link`, `progress` and subtasks.
- `CacheInvalidationPlugin` returns early when `calendarinstances` has no matching row.
- `components VARCHAR(21)` matches the migration, the request rules match `StoreTodoRequest`, and `grep VTODO` finds nothing in app/routes/config/database.

The two corrections to the analyst's facts are accurate and well argued.

TASK-2: PASS
notes: The mapping table covers every field and grades it. All the hard cases the task named are handled: status, priority, percent, date-only DUE, category/tags/workspaces, subtasks via RELATED-TO, recurrence vs RRULE, and link→URL. RFC sections are cited.

TASK-3: PASS
notes: Options A–D are all covered. Each one addresses sync token/ETag, sharing, ACL and CacheInvalidationPlugin, and pgsql/DAV changes are flagged with ⚑. Effort is relative (S/M/L/XL) and the section ends with a comparison table.

TASK-4: PASS
notes: Gives RFC 4791/5545/6578/9253 citations and covers discovery with VTODO-only vs mixed collections. The client table marks anything unconfirmed as unverified.

TASK-5: PASS
notes: The recommendation is clearly labelled "for decision, not decided". It includes a phased plan (0–3), 8 risks and 12 open questions. The open questions cover every decision the task required: two-way, category/workspace mapping, subtasks, recurrence lossiness, and pgsql/DAV changes.

TASK-6: PASS
notes: The report is at the specified path, with a header and tone consistent with the existing `docs/reports/*`. `implementation.md` is filled in. No secrets or `.env` values appear in the report.

## Security

Not run (documentation-only change).

## Overall

APPROVED

No blockers.
