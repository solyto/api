# Brief: Time-tracking entries have no index for the way they are queried

type: feature
id: decade
branch: feature/decade_time-tracking-entries-have-no-index-for-the-way-they-are-queried
date: 2026-11-19
author: Leander Muskalla

Source report: `docs/reports/time-tracking-entries-missing-indexes-2026-11-19.md`

## Summary

**Every hot time-tracking query filters on `user_id` and sorts or ranges on `started_at`, but the table only has the two single-column foreign-key indexes** (`user_id`, `project_id`; `database/migrations/2026_02_18_000001_create_time_tracking_tables.php:36-50`, no later migration adds one). So each query reads **all** of the user's entries and sorts them, and the table only grows: every timer start, every manual entry is a row, and nothing prunes them.

Affected queries:

| caller | query shape | today |
|---|---|---|
| `TimeTrackingService::listEntries` (`:23-33`), `GET /time-tracking/entries` | `user_id = ? ORDER BY started_at DESC`, **no limit** | all user rows + filesort |
| `TimeTrackingService::hasRunningTimer` (`:83-86`), every `POST …/entries/start` | `user_id = ? AND stopped_at IS NULL` | all user rows |
| `IntegrationGateway::runningTimeTrackingEntry` (`app/Shared/Services/IntegrationGateway.php:148-157`), every time-tracking widget refresh | same + `ORDER BY started_at DESC LIMIT 1` | all user rows + filesort |
| `TimeTrackingService::getStatistics` (`:108-142`), `GET …/entries/statistics` | `user_id = ? AND started_at BETWEEN ? AND ?` | all user rows, range filtered row by row |
| `IntegrationGateway::timeTrackingProjectsByLastUse` (`:165-177`), every widget refresh | per project `MAX(started_at) WHERE project_id = ?` | all rows of each project |

The widget path is the one that matters most: it is uncached and runs both the running-timer query and the per-project `MAX(started_at)` subquery on every refresh. The entry list is cached for 5 minutes (`CACHE_TTL_ENTRIES`), but `forgetUser` drops it on every start/stop/edit (`:242-246`), so an active user rebuilds it often, and the rebuild is unbounded.

## Measured

SQLite `EXPLAIN QUERY PLAN` against the same table and indexes (plan shape only; MariaDB/InnoDB behaves the same way for these patterns: a single-column secondary index on `user_id` can't serve the `started_at` order or range):

| query | current indexes | with `(user_id, started_at)` + `(project_id, started_at)` |
|---|---|---|
| list | `INDEX e_user (user_id=?)` + **TEMP B-TREE FOR ORDER BY** | `INDEX e_user_started (user_id=?)`, no sort |
| running timer | `INDEX e_user (user_id=?)` + **TEMP B-TREE FOR ORDER BY** | `INDEX e_user_started (user_id=?)`, no sort |
| statistics | `INDEX e_user (user_id=?)` | `INDEX e_user_started (user_id=? AND started_at>? AND started_at<?)` |
| last use | `INDEX e_project (project_id=?)` | **COVERING** `INDEX e_project_started (project_id=?)` |

No row-count timings: the in-memory SQLite numbers wouldn't say much about MariaDB. The win scales with entries per user; for a few hundred rows it's small, for someone who tracks time daily for a year (1 000+ rows, several per day) the statistics and widget queries go from "read everything" to "read the range / one index entry".

## Suggestion

**1. (Recommended, small) One additive migration with two composite indexes.** In the style of `2025_09_17_150031_add_is_completed_index_to_todos.php`:

```php
Schema::table('time_tracking_entries', function (Blueprint $table) {
    $table->index(['user_id', 'started_at']);
    $table->index(['project_id', 'started_at']);
});
```

- `(user_id, started_at)` serves the list order, the statistics range, and lets the running-timer lookup walk newest-first and stop early.
- `(project_id, started_at)` makes the widget's `withMax('entries', 'started_at')` a covering index lookup.
- MariaDB needs an index on each FK column; both new indexes start with the FK column, so the old single-column FK indexes become redundant. They can stay (cheaper, no FK juggling) or be dropped in the same migration. I'd leave them for now.

Additive, no schema change for the `pgsql`/DAV side, no API change. Migration must be shown before it runs (hard rule). Existing tests cover the queries' results; nothing new is needed beyond `composer test` passing, since indexes don't change behaviour.

**2. (Optional, needs the frontend) Bound `GET /time-tracking/entries`.** Even with the index, `listEntries` returns every entry the user ever created, with the project eager-loaded, and caches the whole collection in Redis. A `from`/`to` window or the standard pagination meta (`ApiResponse` already supports it) would cap both the query and the cache value. This changes the API contract, so it needs the app side to agree first; worth doing only if the list shows up as slow in Pulse after fix 1.

**Not suggested:** a covering index for `withSum('entries', 'duration_minutes')` on the project list. It's cached for a day and only rebuilt on writes, so it isn't on a hot path.
