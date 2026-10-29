# Tasks: time tracking widget: data endpoint and start/stop actions

id: task
analyst: analyst
date: 2026-10-29

<!-- Produced by @analyst from brief.md. -->

## Context (read before starting)

- Token gate needs no change: `AppServiceProvider.php:47-50` lets `widget`-ability
  tokens through on any route **named** `widgets.*`. The new routes only need
  to sit inside the existing `Route::prefix('widgets')->name('widgets.')`
  group in `routes/api.php:257` (throttle `widgets` is applied there too).
- STANDARDS 12.8: Widgets must not call `TimeTrackingService` directly; it
  reaches other modules through `App\Shared\Services\IntegrationGateway`
  (`checkAuth()` on every user-bound method, services resolved late via
  `app(...)`), like `completeTodo()` / `widgetEvents()` do today. Importing
  the TimeTracking **models** (for route model binding / ownership) is fine.
- `WidgetService::yourDay()` holds the locale/timezone resolution and the
  `withoutNulls()` / section-filter pattern to reuse. Do not refactor
  `yourDay()` beyond what sharing a helper strictly needs.
- `TimeTrackingService::startTimer/stopTimer` already clear the user's
  `tt_entries`/`tt_projects` caches; use them, don't write entries directly.
- The app's `POST /time-tracking/entries/start` (409 while running) must not
  change.

## Task breakdown

TASK-1: Add the `en` strings for the time tracking widget (title, empty = "no projects yet", section titles `running` and `projects`) and hand de/fr/es/it to the `translator` agent per `docs/I18N.md`.
  files: resources/lang/en/widgets.php, resources/lang/{de,fr,es,it}/widgets.php (translator)
  depends: none
  risk: low — additive keys; only risk is the developer translating on the fly instead of delegating.

TASK-2: Add IntegrationGateway methods for the widget: the user's running entry (with project), the user's projects ordered by last use (latest entry `started_at` desc, then never-used projects by title, limit param), a start-project-with-switch method, and an idempotent stop-entry method.
  files: app/Shared/Services/IntegrationGateway.php
  depends: none
  risk: medium — the start/switch must run in one `DB::transaction` that `lockForUpdate()`s the user's running entries before deciding (no-op if the project's timer already runs; else `stopTimer` each other running entry, then `startTimer(['project_id' => …])`); NULL ordering differs between MariaDB and SQLite, so order "never used" explicitly (e.g. `ORDER BY last_used IS NULL, last_used DESC, title`) rather than relying on default NULL placement.

TASK-3: Add `WidgetService::timeTracking(User)` building the payload from the brief (kind `time-tracking`, schema_version 1, generated_at, no `refresh_at`, labels, `running` section with one row, `projects` section without the running project and capped at 20, empty sections dropped, `color` only when it matches `/^#[0-9A-Fa-f]{6}$/`, `at` as ISO 8601 with offset in the user's timezone), plus thin `startTimeTracking` / `stopTimeTracking` wrappers that set the gateway user and call TASK-2.
  files: app/Api/Widgets/Services/WidgetService.php
  depends: TASK-1, TASK-2
  risk: medium — the cap of 20 must be applied after excluding the running project (fetch 21 or filter in the query), and `subtitle` must be omitted (not null/empty) when the entry has no description.

TASK-4: Add a `TimeTrackingWidgetResource` (or a resource for the shared format) with an `@OA\Schema` documenting the format additions: row `live` ("elapsed"), row `color` (#RRGGBB, optional), action type `button` with `icon` ("play"/"stop"), action keys `time_tracking.start`/`time_tracking.stop`, route key `time_tracking_project`, icon `timer`, section keys `running`/`projects`, and `refresh_at` optional.
  files: app/Api/Widgets/Resources/TimeTrackingWidgetResource.php (new); possibly app/Api/Widgets/Resources/YourDayResource.php (only doc wording that `refresh_at` is optional)
  depends: TASK-3
  risk: low — documentation and a plain array pass-through; unclear whether the developer should create a separate schema or generalise `YourDayWidget` — the brief says "documenting the additions above in the widget schema"; prefer a new schema alongside `YourDayWidget` and leave `YourDayResource` untouched unless needed.

TASK-5: Add three controller actions and routes in the `widgets.` group: `GET time-tracking` (`time-tracking`), `POST actions/time_tracking.start/{project}` (`actions.time-tracking-start`, binds `TimeTrackingProject`), `POST actions/time_tracking.stop/{entry}` (`actions.time-tracking-stop`, binds `TimeTrackingEntry`); 403 via `abort_unless($this->isResourceOwner(...), 403)`, success envelope with `null` data; full OpenAPI `@OA\Get`/`@OA\Post` annotations with 401/403/404/426/429 like the existing ones.
  files: app/Api/Widgets/Controllers/WidgetController.php, routes/api.php
  depends: TASK-3, TASK-4
  risk: medium — route placeholder names must match the action parameter names (`{project}`, `{entry}`) for implicit binding, and route names must stay under `widgets.*` or the widget token gets 401.

TASK-6: Add Pest tests for the payload, start, stop and token scoping as listed in brief step 5 (payload shape and ordering/cap/exclusion/color filtering/language; widget token and normal token; start idle/switch/no-op/403/404/never two running; stop/no-op/403/404; widget token 401 on `/time-tracking/*`).
  files: app/Api/Widgets/Tests/WidgetApiTest.php (or a new app/Api/Widgets/Tests/TimeTrackingWidgetApiTest.php reusing its helpers)
  depends: TASK-5
  risk: medium — SQLite ignores `lockForUpdate`, so "never two running timers" can only be tested sequentially (repeated/alternating start calls leave exactly one entry with `stopped_at = null`); use `Carbon::setTestNow` so the switched-off timer's `duration_minutes` is deterministic.

TASK-7: Run `composer test` and `composer analyse` and fix anything the change broke (including the regenerated OpenAPI spec if a test covers it).
  files: whatever TASK-1..6 touched
  depends: TASK-1, TASK-2, TASK-3, TASK-4, TASK-5, TASK-6
  risk: low — verification gate required by the project before the job is done.

## Open points (not blocking)

- "Projects ordered by last use" is read as the latest `started_at` of any of the user's entries for that project (running or stopped). The running project is excluded anyway.
- `at` for the running row is rendered in the user's timezone (as `yourDay()` does); any valid offset would satisfy the brief.
- No migration, no new dependency, no DAV/`pgsql` change is needed.
