# Verdict: Time-tracking project list loads every entry to compute one number

id: embarrass
reviewer: reviewer
date: 2026-11-13

## Review

Diff reviewed: `git diff main...HEAD`. Changed files: `TimeTrackingService.php`, `TimeTrackingProjectResource.php`, `TimeTrackingApiTest.php`, `UserService.php`, and job docs. All of them are covered by tasks.md or by the TASK-1 cache-key option it names. Nothing out of scope.

TASK-1: PASS
notes: All four call sites changed as specified (`TimeTrackingService.php:187-188, 195, 208, 227`). No `entries` eager load is left for project responses (grep confirms). The cache key was renamed to `tt_projects_v2`, which is one of the two options tasks.md allows, and it is documented in implementation.md. `UserService::USER_CACHE_KEYS` was updated to match, and no other references to `tt_projects` remain. `forgetUser` and the TTLs are unchanged.

TASK-2: PASS
notes: `TimeTrackingProjectResource.php:30,39` call the 3-argument `whenAggregated`. I checked Laravel's `ConditionallyLoadsAttributes::whenAggregated`. It returns `MissingValue` when the attribute is absent, so `total_duration` is still left out of the project nested in an entry. Otherwise it returns the raw value, and the `(int)` cast turns `NULL` into `0` and a MariaDB decimal string into an int. This avoids both problems noted in tasks.md. The OpenAPI description was updated as asked, and `nullable=true` is kept.

TASK-3: PASS
notes: New tests cover `0` on store, index, show and update (`assertJsonPath` is strict, so `null` and `"0"` would fail). They also cover the non-zero sum (75) on show and update, and the missing `total_duration` in the project nested in `GET /entries`.

TASK-4: PASS
notes: I ran the checks myself in the worktree. `composer test` exited 0 (4983 assertions, 0 failures; the warnings come from the missing `.env` in the sandbox). `composer analyse` exited 0 with no errors. implementation.md records the cache-key decision and the §3 follow-ups.

## Security

None run. The change adds no input handling and changes no authorization.

## Overall

APPROVED

Nothing blocks the merge.
