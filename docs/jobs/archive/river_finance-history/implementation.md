# Implementation: Finance History

id: river
developer: @developer
date: 2026-10-15

## Summary

Wealth fields and budget items (income and expense) now have an editable value
history with at most one value per item and day. Values can be added for any
date up to today (a same-day add replaces the existing value), corrected
(amount and/or date) and deleted. Only the owner can do this, and every change
clears the user's finance cache. `WealthField.currentValue` is now the value
with the latest date. A budget item's `value` column stays the current value
and is kept in sync with its latest-dated history value, so list/show/export
and older clients are unchanged.

`composer test` (exit 0) and `composer analyse` (exit 0, no errors) were run on
the final tree.

### Decisions on the open questions

- **Q-1 (deleting the last budget value):** rejected with 422 (`ErrorResponse`,
  "A budget item needs at least one value."). A wealth field can lose its last
  value; then `currentValue` is `null`.
- **Q-2 (legacy `PUT /finances/budget/{budget}` with `value`):** the value is
  written as today's history value (replacing today's value if there is one),
  then `budgets.value` is synced. The status code and response shape are
  unchanged.
- **Q-3 (source of the current budget value):** `budgets.value` is kept and set to
  the latest-dated history value after every history write, inside the same DB
  transaction. The column is not dropped or renamed.
- **Moving a value onto a date that already has one** (wealth and budget
  `PUT .../values/{value}`): 422 `ValidationErrorResponse` with an error on
  `date`. The two values are never merged silently.
- **Future dates:** rejected (`before_or_equal:today`, format `Y-m-d`).
- **A value that belongs to another field/budget:** 403. This follows the
  reference pattern `TodoController::updateSubtask` (`$subtask->todo_id === $todo->id`
  → 403). A value id that does not exist returns 404 through route binding.

### Endpoints

| Method | Path | operationId | Notes |
| --- | --- | --- | --- |
| PUT | `/finances/wealth/fields/{field}/value` | `updateWealthFieldValue` (unchanged) | now takes an optional `date`; replaces that day's value. Response `data` is now documented as `WealthValue` (it already returned one) |
| PUT | `/finances/wealth/fields/{field}/values/{value}` | `updateWealthValue` | new |
| DELETE | `/finances/wealth/fields/{field}/values/{value}` | `destroyWealthValue` | new |
| GET | `/finances/budget/{budget}/values` | `listBudgetValues` | new, ordered by date |
| POST | `/finances/budget/{budget}/values` | `storeBudgetValue` | new, 201, optional `date`, replaces that day's value |
| PUT | `/finances/budget/{budget}/values/{value}` | `updateBudgetValue` | new |
| DELETE | `/finances/budget/{budget}/values/{value}` | `destroyBudgetValue` | new, 422 for the last value |
| PUT | `/finances/budget/{budget}` | `updateBudget` (unchanged) | description explains the new meaning of `value` |

### Migrations (shown here; not run against any real database)

Both ran only on the in-memory SQLite test database (via `RefreshDatabase`).
Each was also checked against seeded rows in a throwaway test, which was deleted afterwards.

`2026_10_15_000001_add_unique_date_to_wealth_values.php`:
for every `(field_id, date)` that has more than one row, it keeps the row with
the latest `created_at` (ties broken by the highest `id`; a NULL `created_at`
counts as oldest on MariaDB and SQLite) and deletes the rest. Then it adds
`unique(field_id, date)`. `down()` only drops the index; the deleted rows
cannot be restored (owner decision G-1, no backup). The duplicates are found
with a `GROUP BY ... HAVING COUNT(*) > 1` and deleted by id in PHP, because
MariaDB does not allow a `DELETE` with a subquery on the same table.

`2026_10_15_000002_create_budget_values_table.php`:
creates `budget_values` (`id`, `date` date, `value` float, `budget_id` FK →
`budgets` cascade delete, timestamps, `unique(budget_id, date)`). It backfills
one row per existing budget: its `value`, dated `DATE(updated_at)` (falling
back to `created_at`, then today, if NULL). It works in chunks of 500 via
`chunkById`. `budgets.value` is left in place.

## Changes

TASK-1: `database/migrations/2026_10_15_000001_add_unique_date_to_wealth_values.php` (new), with the dedupe and unique index described above.
Follow-up commit: added the migration to `MIGRATION_COMPATIBILITY_ALLOWLIST` in
`tests/Unit/MigrationCompatibilityTest.php`. The test flags `DB::table()->delete`.
The reason given is that only same-day duplicates are dropped and the newest is
kept, per the owner decision.

TASK-2: `database/migrations/2026_10_15_000002_create_budget_values_table.php` (new), with the table and backfill described above.

TASK-3: `app/Api/Finances/Models/BudgetValue.php`, `app/Api/Finances/Factories/BudgetValueFactory.php` (new, mirroring `WealthValue`/`WealthValueFactory`, with a `forBudget` state); `Budget::values()` HasMany.

TASK-4: `WealthService`: `updateValue(field, data)` was renamed to `storeValue`, which adds a value or replaces the one on that date. New: `updateValue(field, value, data)`, which returns 422 on a date clash, and `destroyValue(field, value)`. Every write forgets `wealth_fields`. `listFields` eager-loads values ordered by `date`. `StoreWealthValueRequest` gets `date`; `UpdateWealthValueRequest` is new. Same-day lookups use `whereDate`, because Eloquent stores the `date` cast as `Y-m-d H:i:s`, which matters on SQLite.

TASK-5: `WealthFieldResource`: `values` sorted by `date`, and `currentValue` is the latest-dated value. The shape is unchanged.

TASK-6: `WealthController`: the action behind `PUT {field}/value` is renamed to `storeValue` (the route and operationId are unchanged). New `updateValue`/`destroyValue` actions check the field owner, then that the value belongs to the field. The routes are in `routes/api.php`. OpenAPI annotations were added for the new operations and updated for the changed one.

TASK-7: `BudgetService`: `create` writes the first value dated today; `update` with `value` writes today's value (Q-2). New `listValues`, `storeValue`, `updateValue` (422 on a date clash) and `destroyValue` (422 on the last value). The private `writeValue` does the replace-on-date, and `syncCurrentValue` sets `budgets.value` from the latest-dated value. Writes are wrapped in `DB::transaction`, and each one forgets `budgets`. New `StoreBudgetValueRequest`/`UpdateBudgetValueRequest`. Also part of this commit: `WealthService` now uses `WealthValue::create`/`where` instead of the relation, so Larastan infers the model type. The existing services do the same, e.g. `TodoSubtask::create`.

TASK-8: `BudgetController`: `listValues`, `storeValue`, `updateValue`, `destroyValue` with owner and parent checks, plus OpenAPI annotations; the `updateBudget` description now explains `value`. Four routes were added before `apiResource('budget')`. New `BudgetValueResource` with a `BudgetValue` schema. `BudgetResource` gets `values` only via `whenLoaded` (no endpoint loads it, so list/show are unchanged), and its schema documents `values` and the meaning of `value`.
To clear the Larastan `property.notFound` errors without new baseline entries,
`BudgetResource` and `BudgetValueResource` carry `@mixin Budget` / `@mixin BudgetValue`. This made the six old `BudgetResource` baseline entries unmatched, so they were removed from `phpstan-baseline.neon`.

TASK-9: `app/Api/Finances/Tests/FinanceApiTest.php`, two new `describe` blocks.
- **Wealth values:** add with and without a date; same-day replace; future date → 422; update amount and date; date clash → 422; delete; another user → 403 on add, update and delete; a value of another field → 403; unknown value → 404; `currentValue` is the latest date after a backfill, and values are ordered; cache invalidation on add, update and delete.
- **Budget values:** create writes the first value; legacy `PUT budget` with `value` replaces today's value and updates `value`; `value` follows the latest-dated value through add, update, date move and delete; same-day replace; future date and date clash → 422; deleting the last value → 422; list ordered by date; another user → 403 on all four; a value of another budget → 403; list/show still return `value` and no `values`; cache invalidation.
- The old `WealthService` "updateValue creates a value" test is now "storeValue replaces the value of the same day".
- `FinanceTest.php` needed no change.

TASK-10: `tests/Unit/ClientContractTest.php`, three tests:
- `Budget.value` (number) on list and show.
- `WealthField.values`/`currentValue` (`id`, `date`, `value`) on the field list.
- `PUT wealth/fields/{field}/value` without `date` → 200 with `data.value`.
**Not verified against the frontend:** `solyto/app` (tag 1.0.15) is not available
in this workspace, so the frontend lines that read these fields could not be
checked. The test comments say so instead of citing a line. What is verified:
the fields have been in the resources since the initial commit, and the routes
are in `1.0.15-routes.json`. Someone with access to `solyto/app` should replace
the comments with the real file:line (or drop a test if 1.0.15 doesn't read
that field).

## Known issues / follow-ups

- TASK-10: frontend references in the new contract tests need to be filled in from `solyto/app` 1.0.15, as described above.
- The `PUT finances/wealth/fields/{field}/value` replace-on-same-day behaviour is visible to old clients: a second edit on the same day now overwrites instead of adding a row. This is intended by the brief, and the status code and shape are the same.
- `budget_values` rows written by the migration backfill use a `Y-m-d` date string, while Eloquent writes `Y-m-d H:i:s`. On MariaDB (`DATE` column) both are the same value. Only SQLite would keep them as different strings, and there the backfill runs on an empty table. All lookups use `whereDate`, so they are format-agnostic.
- Race: two concurrent same-day adds could both miss the existing-row lookup; the unique index then rejects the second insert with a `QueryException` (500). Not handled, since it needs a near-simultaneous double submit.
- Environment notes (not code): this worktree had no `vendor/`, no `storage/framework/*` and no `storage/app/public/user`. I ran `composer install` from the lock file and created those directories (git-ignored) before the baseline suite could run. `php artisan l5-swagger:generate` fails here with "Required @OA\PathItem() not found". Running `OpenApi\Generator` directly on the same paths produces the spec, including the new operations and the `BudgetValue` schema, and `OpenApiSpecTest` passes. So this looks like a local config/env problem (no `.env`), not the annotations.
- `.env.testing` shows as modified in `git status`. That change predates this job, and git cannot stage the file ("unsupported file type"), so it was left untouched.
