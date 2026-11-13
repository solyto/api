# Tasks: Time-tracking project list loads every entry to compute one number

id: embarrass
analyst: analyst
date: 2026-11-13

## Scope

This job covers only the main fix (brief §2): compute `total_duration` in SQL and stop loading `entries` for project responses. No migration, and the API contract stays the same.

The related findings in brief §3 (index on `(user_id, started_at)`, unpaginated `GET /time-tracking/entries`, stale categories in `tt_projects`, unscoped `exists` rules) are **out of scope**. The brief calls them separate follow-ups, and §3.1 needs a migration and §3.2 an API contract change. Record them under "Known issues / follow-ups" in `implementation.md`. Do not implement them here.

## Task breakdown

TASK-1: Replace the `entries` eager load with an SQL sum in `TimeTrackingService`: `listProjects` uses `->with('categories')->withSum('entries', 'duration_minutes')`, and `findProject`, `createProject` and `updateProject` use `->load('categories')->loadSum('entries', 'duration_minutes')`.
  files: app/Api/TimeTracking/Services/TimeTrackingService.php
  depends: none
  risk: low. Four call sites in one file, and the cache keys, TTLs and `forgetUser` stay unchanged.
  notes:
  - Change all four call sites in the same commit as TASK-2. Otherwise `total_duration` disappears from the response, because the resource still checks `whenLoaded('entries')`.
  - Old `tt_projects` cache values (with `entries` loaded, no `entries_sum_duration_minutes` attribute) can still be in Redis for up to 24 h after deploy. With TASK-2's resource, those cached projects would leave `total_duration` out until the cache entry expires or is cleared. Pick one: rename the cache key (e.g. `tt_projects_v2`, and update the list in `app/Api/Users/Services/UserService.php:38`), or note this in `implementation.md` as an accepted deploy-window effect. Do not ignore it silently. If you are not sure, ask.

TASK-2: In `TimeTrackingProjectResource`, read `total_duration` from the `entries_sum_duration_minutes` aggregate: an integer `0` when the project has no entries, and still left out when the aggregate is not loaded (the project nested in `TimeTrackingEntryResource`). Update the OpenAPI description to match.
  files: app/Api/TimeTracking/Resources/TimeTrackingProjectResource.php
  depends: TASK-1 (ship together)
  risk: medium. The brief's sample snippet does not do what it says. See notes.
  notes:
  - Do **not** copy `whenAggregated('entries', 'duration_minutes', 'sum', fn ($sum) => (int) $sum, 0)` as written:
    1. In Laravel 12, `whenAggregated` returns `null` without calling the callback when the aggregate is `NULL`. So a project with no entries would return `null`, not `0`.
    2. Passing `0` as the fifth (`$default`) argument means the project nested in `TimeTrackingEntryResource` would now return `total_duration: 0` instead of leaving it out. That is a contract change.
    Use a form that keeps "missing" when the aggregate is not loaded and turns `null` into `0` when it is. For example, call `whenAggregated` with four arguments and handle `null` → `0`, or check `array_key_exists('entries_sum_duration_minutes', $this->resource->getAttributes())`. Both must pass PHPStan (`composer analyse`).
  - Cast to `int`. MariaDB returns `SUM` as a decimal string, so without a cast the JSON would carry `"60"` instead of `60`.
  - OpenAPI: in the `total_duration` description, replace "derived from the entries relation" with wording about an SQL sum of the entries' `duration_minutes`. Keep `nullable=true` / the omitted-when-nested behaviour as it is.

TASK-3: Tests: add a project-API case that a project with no entries returns `total_duration` = `0` (an integer, not null) on index and show. Add a case that `GET /time-tracking/entries` still leaves `total_duration` out of the nested `project`.
  files: app/Api/TimeTracking/Tests/TimeTrackingApiTest.php
  depends: TASK-1, TASK-2
  risk: low. Only tests are added, and the existing `keeps project total_duration fresh …` tests (lines 184-245) already cover non-zero totals and the cache invalidation.
  notes:
  - Use a strict assertion for `0` (e.g. `assertJsonPath('data.total_duration', 0)` plus a check that it is an int, or `toBe(0)`), so that `null` or `"0"` fails.
  - Add show, store and update assertions for `total_duration` if they are not there yet, since TASK-1 changes those three code paths too.
  - Follow docs/TESTING.md.

TASK-4: Run `composer test` and `composer analyse`. Both must exit 0. Then fill in `implementation.md`, including the TASK-1 cache-key decision and the §3 follow-ups.
  files: docs/jobs/embarrass_…/implementation.md
  depends: TASK-1, TASK-2, TASK-3
  risk: low. Verification only.

## Unclear / for the maintainer

- TASK-1 cache-key decision (rename vs. accept the 24 h stale window). Low impact either way. The developer picks one and documents it.
