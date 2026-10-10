# Tasks: time tracking improvements

id: symbol
status: open
analyst:
date: 2026-10-10

<!-- Produced by @analyst from brief.md. -->

## Context / findings

- Entry update validation is **inline** in `TimeTrackingEntryController::update()`
  (`$request->validate([...])`); there is **no `UpdateTimeTrackingEntryRequest`
  class** in the repo (the brief names one). `has_exact_times` is already in the
  model `$fillable`/`$casts` and already accepted by `StoreTimeTrackingEntryRequest`,
  so only the update rule is missing.
- `TimeTrackingProject.total_duration` is **derived**, not stored:
  `TimeTrackingProjectResource` returns it via `whenLoaded('entries', ...sum('duration_minutes'))`.
  The service loads `entries` on project list/show and on project create/update.
  Project mutations forget the `tt_projects` cache, but **entry mutations
  (`createEntry`/`updateEntry`/`destroyEntry`/`startTimer`/`stopTimer`) do not**,
  so `total_duration` in the projects *list* can be stale for up to the 24h TTL.
- `time_tracking_entries.project_id` is a **NOT NULL** uuid FK with
  `onDelete('cascade')`; entries cannot be orphaned, so `project` is never null on
  read even though the entry resource/OpenAPI marks it nullable.
- The start endpoint already enforces one active timer
  (`hasRunningTimer()` + HTTP 409) and is covered by a test.
- Statistics use `$entry->project->categories->first()?->color` as the project
  colour while the project resource has no colour at all — the source of the
  reported colour mismatch.
- The frontend files named in the brief (`src/lib/types/time_tracking.ts`,
  `src/lib/config/apiRoutes.ts`) live in the separate frontend repo and are not
  present here; they are consumers only and out of scope for this job.

## Task breakdown

TASK-1: Accept and persist `has_exact_times` on entry update by adding
`'has_exact_times' => 'sometimes|boolean'` to the inline validation in
`TimeTrackingEntryController::update()` (the field is already fillable, cast and
persisted, so no model/service change is needed).
     files: app/Api/TimeTracking/Controllers/TimeTrackingEntryController.php
            (optionally a new app/Api/TimeTracking/Requests/UpdateTimeTrackingEntryRequest.php
            if the class is extracted — see open questions)
     depends: none
     risk: low — one additive, optional validation rule; existing update
     payloads are unaffected.

TASK-2: Add the `time_tracking_projects.color` column and model support: a new
nullable string migration (added `after('description')`), `color` in
`TimeTrackingProject::$fillable`, and a `withColor()` state + default in
`TimeTrackingProjectFactory`.
     files: database/migrations/2026_10_10_000001_add_color_to_time_tracking_projects.php (new)
            app/Api/TimeTracking/Models/TimeTrackingProject.php
            app/Api/TimeTracking/Factories/TimeTrackingProjectFactory.php
     depends: none
     risk: medium — schema change on an existing table; must be nullable so
     existing rows and clients keep working (show the migration before running it,
     per project rules).

TASK-3: Expose the project colour through the API: accept optional
`color` (`nullable|string|max:7`) in `StoreTimeTrackingProjectRequest` and
`UpdateTimeTrackingProjectRequest`, return `color` from
`TimeTrackingProjectResource` (plus its `@OA\Property`), and add `color` to the
create/update `@OA\RequestBody` annotations in `TimeTrackingProjectController`.
     files: app/Api/TimeTracking/Requests/StoreTimeTrackingProjectRequest.php
            app/Api/TimeTracking/Requests/UpdateTimeTrackingProjectRequest.php
            app/Api/TimeTracking/Resources/TimeTrackingProjectResource.php
            app/Api/TimeTracking/Controllers/TimeTrackingProjectController.php
     depends: TASK-2 (column/model must exist first)
     risk: low — additive optional field; backward compatible.

TASK-4: Make the statistics endpoint's per-project `color` authoritative by
sourcing it from `$entry->project->color` instead of the project's first
category, so statistics and the project resource agree. Keep `total_minutes`
range-scoped (documented in TASK-7) and on the same minutes unit as
`total_duration`; drop the now-unused `categories` eager load if nothing else
needs it.
     files: app/Api/TimeTracking/Services/TimeTrackingService.php (getStatistics)
     depends: TASK-3 (project colour must be exposed)
     risk: low — replaces one read with another; existing category-coloured
     responses are intentionally dropped in favour of the single authority.

TASK-5: Guarantee `TimeTrackingProject.total_duration` is never stale by
invalidating the `tt_projects` cache on every entry mutation — add a
`forget([CACHE_KEY_PROJECTS, ...])` call in `createEntry`, `updateEntry`,
`destroyEntry`, `startTimer` and `stopTimer` (mirroring what project mutations
already do), using the entry's/affected user's id.
     files: app/Api/TimeTracking/Services/TimeTrackingService.php
     depends: none
     risk: medium — cache-invalidation correctness; an update that moves an
     entry between projects must still invalidate the owning user's projects
     cache (per-user key covers both old and new project). Add a service/API test
     asserting `total_duration` updates after entry create/update/delete/stop.

TASK-6 (optional per brief): Let the statistics endpoint accept an optional
`category_id` query filter, validating `category_id => nullable|exists:time_tracking_categories,id`
and restricting entries to projects tagged with that category via
`whereHas('project.categories', ...)` (avoid double counting from the
belongs-to-many join). Add the query parameter to the `@OA\Parameter` block.
     files: app/Api/TimeTracking/Controllers/TimeTrackingEntryController.php (statistics)
            app/Api/TimeTracking/Services/TimeTrackingService.php (getStatistics)
     depends: TASK-4 (both touch getStatistics; land colour change first)
     risk: medium — filtering through the many-to-many pivot can duplicate rows
     if joined naively; prefer whereHas/subquery and cover with a test.

TASK-7: Document the contract clarifications on the affected OpenAPI
annotations/schema descriptions (no behaviour change):
(a) duration-only entries — when `has_exact_times` is false, `started_at`'s date
    is the day anchor by which the entry is placed and `duration_minutes` is the
    authoritative value; `stopped_at` is informational, not authoritative;
(b) project-less entries — entries are cascade-deleted with their project and
    `project` is never null on read, so the client can drop its "uncategorized"
    fallback (also correct `TimeTrackingEntryResource`'s nullable=true on
    `project` to non-nullable);
(c) single active timer — the start endpoint rejects a second running timer with
    409;
(d) statistics — the `from`/`to` range is inclusive on both days, evaluated in
    the app timezone, running timers (`stopped_at` null) are excluded, and
    `total_minutes` is range-scoped while project `total_duration` is all-time.
     files: app/Api/TimeTracking/Resources/TimeTrackingEntryResource.php
            app/Api/TimeTracking/Resources/TimeTrackingProjectResource.php
            app/Api/TimeTracking/Controllers/TimeTrackingEntryController.php
     depends: TASK-3, TASK-4, TASK-6 (document the final field shapes)
     risk: low — documentation/annotation only.

## Decisions & open questions

- **Duration-only anchoring**: brief allows "server-owned anchoring, or a
  documented rule". Chosen: keep current behaviour and document it as
  `started_at`-date-anchored (TASK-7a), since it is backward compatible and needs
  no code change. If product wants the server to derive/anchor instead, that is a
  follow-up code task and should be confirmed.
- **`UpdateTimeTrackingEntryRequest` does not exist.** TASK-1 adds the rule to the
  existing inline validation. Extracting a `UpdateTimeTrackingEntryRequest` class
  to match the brief's wording would also refactor `update()` and is deliberately
  left optional; the field will behave identically either way.
- **`total_duration` semantics**: it remains a derived resource value, not a
  persisted column. TASK-5 closes the cache-staleness gap; if a durable,
  always-correct-at-source value is wanted (e.g. a stored column maintained by
  model events), that is a larger redesign and out of scope.
- **colour validation** reuses the category pattern (`max:7`, hex implied); no
  format enforcement is added so existing category behaviour stays consistent.

## Out of scope

- Frontend changes (separate repo; `src/lib/types/time_tracking.ts` /
  `src/lib/config/apiRoutes.ts` not present here).
- New analytics/reporting product features beyond the current dashboard.
- Changing the `pgsql`/DAV connection, schema or routes (untouched by this job).
