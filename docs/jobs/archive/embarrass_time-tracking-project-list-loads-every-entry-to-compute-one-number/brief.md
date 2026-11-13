# Brief: Time-tracking project list loads every entry to compute one number

type: feature
id: embarrass
branch: feature/embarrass_time-tracking-project-list-loads-every-entry-to-compute-one-number
date: 2026-11-13
author: Leander Muskalla

Source report: `docs/reports/time-tracking-project-list-loads-all-entries-2026-11-13.md`

## Summary

**Every project response loads every time entry of the project, only to add up `duration_minutes`.** `listProjects` eager-loads `entries` for all of a user's projects (`TimeTrackingService.php:187`). The resource uses that relation only for `total_duration` (`TimeTrackingProjectResource.php:36`). The entries themselves are never sent to the client.

Then the whole collection, with every entry model inside, is written to Redis for 24 hours (`CACHE_TTL`, line 185). Any entry change clears that cache entry: start timer, stop timer, create, edit, delete, also from the widget (`IntegrationGateway.php:162-195`). So the next project list rebuilds it from scratch.

The cost grows with the user's whole **history** of time tracking and never goes down.

**Measured (probe, in-memory SQLite, 10 projects × 300 entries = 3 000 entries):**

| | models built | serialized cache value | time |
|---|---|---|---|
| today (`with('entries')`) | 3 010 | **6.77 MB** | 80 ms |
| `withSum('entries', 'duration_minutes')` | 10 | **21 KB** | 7 ms |

Both versions return the same totals (the probe asserted that). A daily tracker who starts and stops a few timers a day reaches 3 000 entries in about two years. After that, every list request after a timer change writes about 7 MB into the `user_data` Redis store and reads it back.

**Recommendation:** compute the total in SQL with `withSum` / `loadSum` and stop loading `entries` (§2). It is a small change, it needs no migration, and the response stays the same.

## 1. Where it happens

`app/Api/TimeTracking/Services/TimeTrackingService.php`:

- `listProjects` (181-190): `->with(['categories', 'entries'])->get()`, cached for 86 400 s under `tt_projects`.
- `findProject` (192-197), `createProject` (207) and `updateProject` (226): `->load(['categories', 'entries'])`. These are one project each, but a long-running project still has all its entries loaded on every show or edit.
- `forgetUser` (241-245) clears `tt_projects` on every entry mutation, so the 24 h TTL rarely helps an active user.

`app/Api/TimeTracking/Resources/TimeTrackingProjectResource.php:36`:

```php
'total_duration' => $this->whenLoaded('entries', fn () => $this->entries->sum('duration_minutes')),
```

The `entries` relation is not used anywhere else in project responses. `TimeTrackingEntryResource` nests the project without `entries`, so `total_duration` is already left out there.

## 2. Suggested fix

Service:

```php
TimeTrackingProject::forUser($user->id)
    ->with('categories')
    ->withSum('entries', 'duration_minutes')
    ->get();
```

Use `$project->load('categories')->loadSum('entries', 'duration_minutes')` in `findProject`, `createProject` and `updateProject`.

Resource: read `entries_sum_duration_minutes` instead of summing the relation, for example
`$this->whenAggregated('entries', 'duration_minutes', 'sum', fn ($sum) => (int) $sum, 0)`. Today a project with no entries returns `0`. `withSum` gives `NULL` there, so cast it, otherwise old frontends would get `null`. Update the OpenAPI description of `total_duration` ("derived from the entries relation") to match.

Tests: the existing project tests in `app/Api/TimeTracking/Tests/TimeTrackingApiTest.php` already check `total_duration`. Add one case for a project without entries that asserts `0`.

## 3. Related, smaller findings (same module)

These are separate from the main fix. Each could be its own follow-up.

1. **Missing index for the statistics range.** `getStatistics` (108-115) filters `user_id` + `started_at` range. `time_tracking_entries` only has the FK indexes on `user_id` and `project_id` (`2026_02_18_000001_create_time_tracking_tables.php:36-50`). So MariaDB reads every entry of the user and then filters by date. `listEntries` also sorts by `started_at`. An index on `(user_id, started_at)` would cover both. This needs a migration, so show it to the maintainer first.
2. **`GET /time-tracking/entries` returns the whole history, unpaginated** (`listEntries`, 23-32). Each entry also carries its own serialized copy of the project in the 5-minute cache. This grows like the main finding, but fixing it changes the API contract (pagination or a date range). Check against `frontend-backwards-compatibility-2026-10-14.md` before changing it.
3. **Stale categories in the project list (correctness, not performance).** `updateCategory` / `destroyCategory` (163-179) only clear `tt_categories`. `tt_projects` embeds the categories, so after a category is renamed, recoloured or deleted, the project list shows the old value for up to 24 h, or until the next entry change. Fix: also clear `tt_projects` there.
4. **Unscoped `exists` rule (minor, security hygiene).** `statistics` validates `category_id` with `exists:time_tracking_categories,id` (`TimeTrackingEntryController.php:396`), which accepts other users' category ids. No data leaks, because the entries are still filtered by the user. But it lets a caller probe which ids exist. Scope the rule to `user_id`, as `Store/UpdateTimeTrackingProjectRequest` already do for `category_ids.*`. The entry requests' `project_id` rules (`StoreTimeTrackingEntryRequest.php:17`, `UpdateTimeTrackingEntryRequest.php:17`, `start` at `TimeTrackingEntryController.php:261`) are also unscoped. The controller's `isResourceOwner` check still blocks any cross-user write with a 403, so this is the same kind of existence oracle (422 vs 403), not an access-control hole.

## 4. Not checked

- Production entry counts per user. The probe numbers are from SQLite and only show the scale. Real MariaDB/Redis timings will differ, but the serialized size does not depend on the database.
- Whether the frontend relies on project responses being cached for 24 h. The fix keeps the cache, it only makes the cached value smaller.
