# Tasks: Report: VTODO

id: scope
analyst: @analyst
date: 2026-10-14

<!-- Produced by @analyst from brief.md. -->

## Scope note

This is a **report-only** job: the brief asks "can solyto offer CalDAV
sync of todos as VTODO components, and if so, how?" with the answer going to
`docs/reports/`. The only file that should be created is the report itself.
**No code, migrations, config, or DAV changes** are in scope. If any of the
tasks below seem to need a code change to answer the question, write that
down in the report as a recommendation. Don't make the change.

The brief's `Why` and `Out of scope` sections are empty. The report should
not assume a product decision (for example, "todos must move to pgsql"). It
should lay out options and trade-offs, and list what a human still has to
decide under open questions.

## Facts already found during analysis (for the developer to check and cite)

- Todos live on the **default (MariaDB) connection** as Eloquent models:
  `Todo` (UUID PK; title, description, link, is_completed, completed_at,
  priority, status enum `backlog|pending|in-progress|waiting|almost-done`,
  effort, progress, due_at as **date only**, recurrence_frequency/interval/
  ends_at, parent_task_id, auto_generated), plus `TodoSubtask` (title,
  is_completed), `TodoCategory` (one per todo), `TodoWorkspace` (groups
  categories through `workspace_category`), and tags (many-to-many).
- Recurrence is handled by the app: when a recurring todo is completed,
  `TodoService::spawnNextOccurrence()` creates a **new row**. There is no
  RRULE on one master item, which is the reverse of how VTODO RRULE works.
- Todos are written from several places besides `TodoService`:
  `QuickAddService`, `IntegrationGateway`, `SolytoBot` (Telegram), and
  `EventAttachmentService` (event↔todo links). Exports are in
  `TodoExportService`. A sync design has to handle every one of these
  writers.
- The DAV server (`app/Dav/Factories/DavServerFactory.php`) uses the
  **stock** `Sabre\CalDAV\Backend\PDO` on the `pgsql` connection, not
  `AppCalendarsPDO`. The `components` column already exists in the Sabre
  schema (`2025_12_08_161442_create_dav_tables.php`). However,
  `AppCalendarsPDO::createCalendarCustom()` hardcodes
  `'components' => 'VEVENT'`, and `getExpandedEventsCustom()` /
  `EventDTO` only handle VEVENT.
- `CacheInvalidationPlugin` only invalidates calendar/contact caches. It
  knows nothing about todos.
- The repo has no VTODO handling anywhere today (grep finds nothing outside
  this job).

## Task breakdown

TASK-1: Check the facts above against the code and record them, with file/line references, in a "Current state" section of the report.
  files: app/Api/Todos/**, app/Dav/Factories/DavServerFactory.php, app/Dav/Backend/AppCalendarsPDO.php, app/Dav/Plugins/CacheInvalidationPlugin.php, app/Dav/DTOs/EventDTO.php, app/Dav/Helpers/ICalendarHelper.php, database/migrations/*todo*, database/migrations/2025_12_08_161442_create_dav_tables.php, app/Shared/Services/{QuickAddService,IntegrationGateway}.php, app/Bots/SolytoBot.php, app/Api/Export/Services/TodoExportService.php (read-only)
  depends: none
  risk: low — read-only; the only risk is a wrong claim, which review catches.

TASK-2: Write a field-mapping table from the solyto todo model to RFC 5545 VTODO properties, and mark each field as lossless, lossy, or unmappable.
  files: docs/reports/<report>.md (new; see TASK-6 for the name)
  depends: TASK-1
  risk: medium — the tricky mappings need careful judgment: status enum vs VTODO STATUS (NEEDS-ACTION/IN-PROCESS/COMPLETED/CANCELLED), priority strings vs PRIORITY 0–9, effort/progress vs PERCENT-COMPLETE, date-only due_at vs DUE, category/workspace/tags vs CATEGORIES or separate calendar collections, subtasks vs child VTODOs with RELATED-TO, the spawn-a-row recurrence model vs RRULE, link vs URL. Use sabre/vobject and RFC 5545/4791 as the source of truth; don't rely on memory.

TASK-3: Describe and compare the architectural options for exposing todos over CalDAV, with pros, cons, and effort for each.
  files: docs/reports/<report>.md
  depends: TASK-1, TASK-2
  risk: medium — this is the core judgment of the report. At minimum it should cover: (a) a custom Sabre CalDAV backend/node tree that serves VTODOs straight from MariaDB `todos`, with no data duplication; (b) a two-way bridge that mirrors todos into VTODO calendar objects in pgsql and syncs back through DAV hooks, with consistency and loop risks; (c) moving todos to pgsql calendar objects as the source of truth, which is a big migration and touches every writer; (d) a read-only feed or one-way export (for example, an ICS subscription) as a smaller alternative. For each option, cover sync-token/ctag/etag handling (the Sync plugin), sharing, ACL, the CacheInvalidationPlugin, and the hard rule that the `pgsql` schema and DAV routes must not change without flagging it.

TASK-4: Look into client compatibility and RFC requirements that affect the design, and cite sources.
  files: docs/reports/<report>.md
  depends: none
  risk: low — research only. Points to cover: how clients find todo collections (supported-calendar-component-set; whether VEVENT+VTODO in one collection or VTODO-only collections work better), and how Apple Reminders, Thunderbird, DAVx5+tasks.org/jtx Board, and Evolution handle subtasks (RELATED-TO), recurring VTODOs, and PERCENT-COMPLETE/STATUS. Mark anything that can't be confirmed as unverified.

TASK-5: Recommend an option with a rough phased plan (for example, read-only first, then two-way), plus a risk list and an "Open questions for a human" section.
  files: docs/reports/<report>.md
  depends: TASK-2, TASK-3, TASK-4
  risk: medium — the recommendation will shape a later feature job. Keep it clearly labeled as a recommendation. Open questions must include anything the empty Why/Out-of-scope sections leave undecided: whether two-way sync is required, how categories/workspaces map to collections, whether subtasks must sync, how lossy recurrence may be, and whether touching pgsql/DAV routing is acceptable.

TASK-6: Put the report together at `docs/reports/vtodo-caldav-sync-2026-10-14.md`, matching the tone and structure of the existing reports in `docs/reports/`, and fill in `implementation.md`.
  files: docs/reports/vtodo-caldav-sync-2026-10-14.md (new), docs/jobs/scope_report-vtodo/implementation.md
  depends: TASK-1 … TASK-5
  risk: low — documentation only. Make sure no secrets or `.env` values end up in the report.

## Unclear / flagged

- The brief doesn't say whether the report should include an effort
  estimate or just feasibility. TASK-3 and TASK-5 ask for rough relative
  effort only (S/M/L), not time estimates.
- Any option that changes the `pgsql` DAV schema or `DavServerFactory`
  wiring must be clearly flagged in the report, per the project hard rules.
