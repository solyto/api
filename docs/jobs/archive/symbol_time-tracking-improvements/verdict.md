# Verdict: time tracking improvements

id: symbol
reviewer: reviewer
date: 2026-10-10

<!-- Produced by @reviewer and/or @security after implementation. -->

## Review

Reviewed `git diff main...HEAD` (12 source files + job docs; no unrelated
changes). Scope matches tasks.md exactly.

TASK-1: PASS
notes: `app/Api/TimeTracking/Controllers/TimeTrackingEntryController.php:163`
adds `'has_exact_times' => 'sometimes|boolean'` to the inline update validation;
the field is already `$fillable`/cast (`Models/TimeTrackingEntry.php:29,38`) and
`updateEntry()` persists via `$entry->update($data)`. API test sets and clears
the flag and asserts persistence. No `UpdateTimeTrackingEntryRequest` class
extraction — matches the documented open-question decision.

TASK-2: PASS
notes: New nullable migration
`database/migrations/2026_10_10_000001_add_color_to_time_tracking_projects.php`
(`string('color')->nullable()->after('description')`, reversible `down()`),
`color` in `TimeTrackingProject::$fillable`, and factory default +
`withColor()` state. `after()` is ignored by SQLite grammar; fine on MariaDB.

TASK-3: PASS
notes: `StoreTimeTrackingProjectRequest` (`nullable|string|max:7`),
`UpdateTimeTrackingProjectRequest` (`sometimes|nullable|string|max:7`),
`TimeTrackingProjectResource` returns `color`, and both create/update
`@OA\RequestBody` blocks include `color`. Store/update go through
`createProject`/`updateProject` which mass-assign via `$fillable`. API test
covers store and update.

TASK-4: PASS
notes: `TimeTrackingService::getStatistics()` now sources the per-project
`color` from `$entry->project->color` and eager-loads plain `project` instead of
`project.categories`. Single colour authority now agrees with the project
resource. Service test asserts equality.

TASK-5: PASS
notes: `createEntry`/`updateEntry`/`destroyEntry`/`startTimer`/`stopTimer` all
`forget([CACHE_KEY_PROJECTS, <user>])` in addition to the entries key
(`Services/TimeTrackingService.php:42,53,64,82,105`). Per-user key covers both
projects when an entry moves. Two API tests assert `total_duration` across
create→update→delete and start→stop.

TASK-6: PASS
notes: `statistics()` validates
`category_id => nullable|exists:time_tracking_categories,id` and forwards it to
`getStatistics(..., ?int $categoryId)`, applied via
`whereHas('project.categories', ...)` (EXISTS subquery, no pivot row
duplication) — correct. `@OA\Parameter` added. Tests cover narrowing and the
422 on an unknown id.

TASK-7: PASS
notes: Documentation-only. OpenAPI annotations updated for duration-only
anchoring (`started_at` day anchor, `duration_minutes` authoritative,
`stopped_at` informational), cascade-delete/non-null `project` (schema no
longer marks it nullable), single-active-timer 409, and statistics
range/timezone inclusivity + running-timer exclusion + range-scoped
`total_minutes` vs all-time `total_duration`. No behaviour change.

## Security

No security-relevant changes. The new `category_id` filter is scoped by
`forUser($user->id)` on entries, so a foreign category id can only yield an
empty result, not data leakage; ownership checks on project/entry routes are
untouched. Colour is a nullable string (`max:7`) with no injection surface.

## Notes (non-blocking)

- The developer flagged that `php artisan l5-swagger:generate` fails in the
  environment ("Required @OA\PathItem() not found"); same annotation sources
  scan cleanly with standalone swagger-php. This is a pre-existing harness
  issue, not introduced here.
- The new migration was only exercised against in-memory SQLite, never a real
  MariaDB instance.

## Overall

APPROVED

All seven tasks are implemented as specified, changes are backward compatible
(new project `color` nullable/optional), no unrelated refactors, and no
out-of-scope source edits. Nothing needs to change before merge.
