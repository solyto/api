# Implementation: time tracking improvements

id: symbol
developer:
date: 2026-10-10

## Summary

Closed the API/contract gaps that forced the frontend to duplicate or
second-guess the time-tracking model:

- `has_exact_times` is now accepted and persisted on entry **update**.
- `TimeTrackingProject` gained its own `color` column, exposed through the API
  (project create/update + resource) and used as the single colour authority in
  the statistics endpoint (replacing the first-category colour).
- `TimeTrackingProject.total_duration` is guaranteed fresh by invalidating the
  `tt_projects` cache on every entry mutation (create/update/delete, timer
  start/stop) — the real staleness gap behind "confirm it's maintained".
- The statistics endpoint accepts an optional `category_id` query filter
  (sourced via `whereHas` subquery, no pivot double counting).
- Contract clarifications (duration-only anchoring, cascade-delete / non-null
  project, single active timer 409, statistics range/timezone semantics) are
  documented on the affected OpenAPI annotations; `project` is no longer marked
  nullable in the entry schema.

All changes are backward compatible (new fields optional; `color` nullable).

## Changes

TASK-1: Accept and persist `has_exact_times` on entry update — added
`'has_exact_times' => 'sometimes|boolean'` to the inline validation in
`TimeTrackingEntryController::update()` (the field was already fillable, cast
and persisted via `StoreTimeTrackingEntryRequest`); reflected the field in the
store/update `@OA\RequestBody` annotations; added an API test that sets and
clears the flag.
files: `app/Api/TimeTracking/Controllers/TimeTrackingEntryController.php`,
`app/Api/TimeTracking/Tests/TimeTrackingApiTest.php`

TASK-2: Added the nullable `time_tracking_projects.color` string column
(added `after('description')`), `color` in `TimeTrackingProject::$fillable`,
and a factory default + `withColor()` state.
files: `database/migrations/2026_10_10_000001_add_color_to_time_tracking_projects.php` (new),
`app/Api/TimeTracking/Models/TimeTrackingProject.php`,
`app/Api/TimeTracking/Factories/TimeTrackingProjectFactory.php`,
`app/Api/TimeTracking/Tests/TimeTrackingTest.php`

TASK-3: Exposed the project colour through the API — optional
`color` (`nullable|string|max:7` create / `sometimes|nullable|string|max:7`
update), returned from `TimeTrackingProjectResource` (+ `@OA\Property`), added to
the create/update `@OA\RequestBody` annotations; API test for store/update.
files: `app/Api/TimeTracking/Requests/StoreTimeTrackingProjectRequest.php`,
`app/Api/TimeTracking/Requests/UpdateTimeTrackingProjectRequest.php`,
`app/Api/TimeTracking/Resources/TimeTrackingProjectResource.php`,
`app/Api/TimeTracking/Controllers/TimeTrackingProjectController.php`,
`app/Api/TimeTracking/Tests/TimeTrackingApiTest.php`

TASK-4: Statistics colour now comes from `$entry->project->color` instead of
the project's first category; dropped the now-unused `project.categories`
eager load (plain `project` is loaded). `total_minutes` remains range-scoped on
the same minutes unit as `total_duration`. Service test asserts statistics
colour equals the project colour.
files: `app/Api/TimeTracking/Services/TimeTrackingService.php`,
`app/Api/TimeTracking/Tests/TimeTrackingApiTest.php`

TASK-5: Guaranteed `total_duration` freshness — entry mutations
(`createEntry`, `updateEntry`, `destroyEntry`, `startTimer`, `stopTimer`) now
also `forget([CACHE_KEY_PROJECTS, $userId])`, mirroring what project mutations
already did (per-user key covers both projects when an entry moves). Two API
tests exercise the projects-list `total_duration` across
create→update→delete and across start→stop; verified the create test fails
without the invalidation.
files: `app/Api/TimeTracking/Services/TimeTrackingService.php`,
`app/Api/TimeTracking/Tests/TimeTrackingApiTest.php`

TASK-6: Statistics endpoint accepts an optional `category_id` query filter
(`nullable|exists:time_tracking_categories,id`), forwarded to
`getStatistics(..., ?int $categoryId)` and applied with
`whereHas('project.categories', ...)` (subquery, no join duplication); added the
`@OA\Parameter`. Tests: filter narrows stats to the tagged project; unknown id
→ 422.
files: `app/Api/TimeTracking/Controllers/TimeTrackingEntryController.php`,
`app/Api/TimeTracking/Services/TimeTrackingService.php`,
`app/Api/TimeTracking/Tests/TimeTrackingApiTest.php`

TASK-7: Documented the contract on the OpenAPI annotations (no behaviour
change): (a) duration-only entries — `started_at`'s date is the day anchor,
`duration_minutes` is authoritative, `stopped_at` informational; (b) entries
are cascade-deleted with their project and `project` is never null on read
(entry schema no longer marks it nullable, so the client can drop its
"uncategorized" fallback); (c) the start endpoint rejects a second running
timer with 409; (d) statistics `from`/`to` are inclusive day ranges evaluated
in the app timezone, running timers are excluded, and `total_minutes` is
range-scoped while project `total_duration` is all-time. Verified via
swagger-php standalone scan of the generated document that all descriptions and
the non-nullable `project` appear in the emitted spec.
files: `app/Api/TimeTracking/Resources/TimeTrackingEntryResource.php`,
`app/Api/TimeTracking/Resources/TimeTrackingProjectResource.php`,
`app/Api/TimeTracking/Controllers/TimeTrackingEntryController.php`

## Known issues / follow-ups

- `php artisan l5-swagger:generate` fails in this environment with
  "Required @OA\PathItem() not found" (the booted generator finds no paths at
  all). The same annotation sources scan cleanly with swagger-php's standalone
  `vendor/bin/openapi app/OpenApi app/Api` (exit 0, full document emitted
  including the new properties/descriptions), so this is an environmental/
  pre-existing harness issue, not caused by this branch.
- One full-suite run showed a flaky, order-dependent failure in
  `App\Api\Export\Tests\ExportTest` (CSV export `fopen` path); it passes in
  isolation and on rerun (674 passed). Unrelated to TimeTracking.
- `tp_` cache keys: `total_duration` is a derived resource value, not a stored
  column — TASK-5 closes the staleness gap. If a durable, always-correct-at-
  source value is wanted (stored column maintained by model events), that is a
  larger redesign and out of scope.
- Environment setup for verification (all gitignored): `composer install`
  (locked dependencies only, no new packages), empty `.env`, and the
  `storage/framework/*` skeleton dirs were created; the migration
  `2026_10_10_000001_add_color_to_time_tracking_projects.php` was only
  exercised against the tests' in-memory SQLite, never against a real database.
- Pre-existing style debt in `app/Api/TimeTracking` (double blank lines after
  namespace, missing trailing commas in request rules, service formatting) is
  flagged by `pint --test` but predates this branch and was left untouched
  per the no-refactor rule.