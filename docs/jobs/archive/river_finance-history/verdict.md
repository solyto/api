# Verdict: Finance History

id: river
reviewer: @reviewer
date: 2026-10-15

## Review

Reviewed `git diff main...HEAD` (base `main` from `.manigot/manigot.json`) on
branch `feature/river_finance-history`. Every change in the diff is in
`implementation.md`. The `chore: commit all` commits only touch the job docs
(`brief.md`, `tasks.md`, `tokens.json`).

Verification run by the reviewer on the final tree:
- `composer test`: exit 0, 3445 assertions, no failures. Every test shows a
  PHPUnit *warning* `file_get_contents(/workspace/.env): Failed to open stream`.
  The cause is the environment: there is no `.env` here, and `.env.testing` is
  mounted as a char device (`/dev/null`). The code is not the cause, and the
  assertions run.
- Filtered run (Wealth values, Budget values, WealthService, ClientContract,
  MigrationCompatibility, OpenApi): 853 assertions, no failures.
- `composer analyse`: exit 0, `[OK] No errors`.
- `php artisan route:list --path=finances`: the new routes are registered, and the
  budget value routes come before `apiResource('budget')`.

TASK-1: PASS
notes: `2026_10_15_000001_add_unique_date_to_wealth_values.php` finds duplicates
with GROUP BY/HAVING. Per `(field_id, date)` it keeps the newest row (`created_at`
desc, then `id` desc) and deletes the rest by id, which works on MariaDB without
the same-table subquery restriction. It then adds `unique(field_id, date)`.
`wealth_values.date` is a `date` column, so grouping by day is exact. This matches
G-1. The allowlist entry in `MigrationCompatibilityTest` is justified.

TASK-2: PASS
notes: The table matches the spec: FK with cascade delete and `unique(budget_id, date)`.
The backfill uses `chunkById(500)`, dates rows at `DATE(updated_at)` (falling back to
`created_at`/now) and leaves `budgets.value` untouched. It is safe on a fresh database.

TASK-3: PASS
notes: `BudgetValue` and its factory mirror `WealthValue` (same casts), and
`Budget::values()` is a HasMany.

TASK-4: PASS
notes: `storeValue` replaces on the same day via `whereDate` or creates a new value,
and defaults to today. `updateValue` returns 422 on a date clash. `destroyValue` is
added. Each write forgets `[wealth_fields, user_id]`. `listFields` orders values by
date. The request rules match the plan (`sometimes|date_format:Y-m-d|before_or_equal:today`).

TASK-5: PASS
notes: `currentValue` is `sortBy('date')->last()` and `values` are sorted by date.
The shape is unchanged.

TASK-6: PASS
notes: The legacy `PUT {field}/value` keeps its route and operationId. The new
`PUT`/`DELETE {field}/values/{value}` actions check `isResourceOwner` on the field
and `$value->field()->is($field)`, returning 403 as in `TodoController::updateSubtask`.
OpenAPI annotations have unique operationIds.

TASK-7: PASS
notes: `create` writes the first value for today. Legacy `update` with `value`
writes today's value and syncs `budgets.value`. `storeValue`/`updateValue`/`destroyValue`
run in `DB::transaction` and keep `budgets.value` equal to the latest-dated value.
Deleting the last value returns 422 (Q-1). Each write forgets `[budgets, user_id]`.
`BudgetService::create` has no other callers, so the required `value` is always present.

TASK-8: PASS
notes: The routes are registered before `apiResource`. Ownership and the
value-to-budget check are in place on all four actions (list only needs the
owner check). `BudgetResource.values` is added only via `whenLoaded`, so
list/show are unchanged. The `BudgetValue` schema, the new operations and the
updated `updateBudget` description are documented. `@mixin` lets six now-unmatched
baseline entries be removed, which is needed for analyse to pass.

TASK-9: PASS
notes: Tests cover every case in the plan for both wealth and budget: ownership,
cross-parent 403, 404, future date, date clash, replace on the same day,
deleting the last value, sync of `value`, ordering and cache invalidation. The
old `updateValue` test was updated to the replace semantics.

TASK-10: PASS
notes: The three contract tests pin `Budget.value`, `WealthField.values`/`currentValue`
and `PUT .../value` without `date`. As the plan required, `implementation.md` and
the test comments say openly that the frontend 1.0.15 references could not be
verified from this repo.

## Security

Not run separately. Every nested value endpoint checks the owner of the parent
and that the child belongs to it, and tests cover both checks. No secrets, no
pgsql/DAV changes.

## Overall

APPROVED

No blockers. Before merging, the owner still has to see both migrations before
they run against a real database, as the hard rules require. They are described
in `implementation.md` and have only run on the in-memory SQLite test database.
