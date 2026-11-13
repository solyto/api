# Implementation: Time-tracking project list loads every entry to compute one number

id: embarrass
developer: developer
date: 2026-11-13

## Summary

Project responses now get `total_duration` from an SQL `SUM` (`withSum` / `loadSum`) instead of loading every time entry of the project. The response contract stays the same: an integer, `0` for a project without entries, and still left out of the project nested in an entry. The cached `tt_projects` value no longer holds entry models.

## Changes

TASK-1: `app/Api/TimeTracking/Services/TimeTrackingService.php`: `listProjects` uses `->with('categories')->withSum('entries', 'duration_minutes')`. `findProject`, `createProject` and `updateProject` use `->load('categories')->loadSum('entries', 'duration_minutes')`.
Cache-key decision: renamed `tt_projects` to `tt_projects_v2`, following the `feed_items_v2` precedent in `FeedService`. Old cached values (with `entries` loaded and no sum attribute) would otherwise leave `total_duration` out for up to 24 h after deploy. `app/Api/Users/Services/UserService.php` `USER_CACHE_KEYS` was updated so that account deletion still clears the new key. Orphaned `tt_projects` entries expire on their own within 24 h.

TASK-2: `app/Api/TimeTracking/Resources/TimeTrackingProjectResource.php`: `total_duration` reads the aggregate through `whenAggregated('entries', 'duration_minutes', 'sum')` with three arguments, so a missing aggregate stays a `MissingValue` (omitted) and a loaded one is cast to `int`. The cast turns `NULL` into `0` and MariaDB's decimal string into a number. I did not use the brief's five-argument form because it would return `null` for a project without entries and `0` in the nested project. The OpenAPI description now says the value is summed in SQL, is `0` without entries, and is omitted when nested.

TASK-3: `app/Api/TimeTracking/Tests/TimeTrackingApiTest.php`: added tests that
- a project without entries returns `total_duration` `0` on store, index, show and update. `assertJsonPath` uses `assertSame`, so `null` or `"0"` fails.
- the sum is correct (75) on show and update.
- `GET /time-tracking/entries` leaves `total_duration` out of the nested `project`.

The existing tests already cover index plus cache invalidation.

TASK-4: `composer test` (exit 0, 3 consecutive runs) and `composer analyse` (exit 0, no errors) both pass.

## Known issues / follow-ups

- The first `composer test` run in this fresh worktree (right after `composer install`, which created `vendor/`) reported 2 failures. I did not capture which tests failed. The next three full runs, with no code changes, all passed. This is probably first-run or environment state, not this change, but it is worth knowing about. All runs also show a warning for each test because there is no `/workspace/.env` in the sandbox. That is environmental.
- Out of scope (brief §3), for separate follow-ups:
  1. Add an index on `time_tracking_entries (user_id, started_at)` for `getStatistics` and `listEntries`. This needs a migration, so show it to the maintainer first.
  2. `GET /time-tracking/entries` is unpaginated and returns the whole history. Fixing this is an API contract change, so check it against the frontend backwards-compatibility report.
  3. `updateCategory` / `destroyCategory` only clear `tt_categories`. The project list (`tt_projects_v2`) embeds the categories and can show stale ones for up to 24 h.
  4. Unscoped `exists` rules (`statistics` `category_id`; the entry `project_id` rules on store, update and start) act as an existence oracle (422 vs 403). Scope them to `user_id`.
