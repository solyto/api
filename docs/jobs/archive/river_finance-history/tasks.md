# Tasks: Finance History

id: river
analyst: @analyst
date: 2026-10-15

<!-- Produced by @analyst from brief.md. -->

## Gates to clear before implementation

- **G-1 (cleared 2026-10-15, owner decision):** The owner (Leander Muskalla)
  confirmed the duplicate cleanup for `wealth_values` as proposed. Per
  `(field_id, date)`, keep the most recently created row (latest
  `created_at`, ties broken by highest `id`) and delete the rest. No backup
  table or export is required. TASK-1 may be written. The migration must still
  be shown before it is run (hard rules).
- **Income vs. expense:** settled. The brief's "What" section asks for history
  on both income and expense items (the shared `budgets` table), so this plan
  covers both.
- **Future dates:** rejected (`before_or_equal:today`), which is the default
  the brief expects.

## Open questions (not blocking, but say which way you went in implementation.md)

- **Q-1. What happens when the last value of a budget item is deleted?**
  `budgets.value` is a non-null float, and older clients read `value` as a
  number. Proposal: reject with 422 so a budget item always has at least one
  value. A wealth field can have zero values (`currentValue` is already
  nullable), so deleting its last value is fine.
- **Q-2. What does the legacy `PUT /finances/budget/{budget}` with `value` do?**
  The 1.0.15 frontend calls it (`tests/Fixtures/client-contract/1.0.15-routes.json`),
  so it must keep working (STANDARDS 13.1). Proposal: treat `value` there as
  "add a value dated today", which replaces today's value if one exists. The
  current value and history then stay consistent.
- **Q-3. Where does a budget item's current value come from?** Proposal: keep
  `budgets.value` as the item's current value and have `BudgetService` set it
  to the latest-dated history value on every history change. Do not drop or
  rename the column (STANDARDS 13.4). The list, `BudgetResource.value`,
  `FinanceIncomeExportService` and the frontend's totals then keep working
  without changes. The alternative is to compute it from the relation on
  every read. That touches more reads (export, list) for no client-visible
  gain.

## Task breakdown

TASK-1: Add a migration that removes duplicate `wealth_values` rows per `(field_id, date)`, keeping the most recently created one (latest `created_at`, ties broken by highest `id`), and then adds a unique index on `(field_id, date)`.
     files: database/migrations/2026_10_15_000001_add_unique_date_to_wealth_values.php (new)
     depends: G-1 (cleared: owner confirmed keep-newest, no backup)
     risk: high — it deletes user data irreversibly (`down()` can only drop the index), and the delete query must work on both MariaDB and in-memory SQLite. Per the hard rules, show the migration before running it.

TASK-2: Add a migration that creates `budget_values` (`id`, `date` date, `value` float like `budgets.value`, `budget_id` FK to `budgets` with cascade delete, timestamps, unique `(budget_id, date)`). In the same migration, give every existing budget one row: its `value`, dated `DATE(updated_at)`. Leave `budgets.value` in place.
     files: database/migrations/2026_10_15_000002_create_budget_values_table.php (new)
     depends: none
     risk: medium — the backfill is a data migration on production rows; it must be idempotent-safe on a fresh DB and SQLite-compatible, and must be shown before running.

TASK-3: Add a `BudgetValue` model and factory, plus a `Budget::values()` HasMany relation. Follow `WealthValue`/`WealthValueFactory` (casts `date` → date, `value` → float).
     files: app/Api/Finances/Models/BudgetValue.php (new), app/Api/Finances/Factories/BudgetValueFactory.php (new), app/Api/Finances/Models/Budget.php
     depends: TASK-2
     risk: low — mirrors an existing model one-to-one.

TASK-4: Rework wealth value handling in `WealthService`. Adding a value takes an optional `date` (default today) and replaces the field's existing value on that date (e.g. `updateOrCreate` on `field_id` + `date`). Add update (amount and/or date) and delete of a single value. Every write forgets `[wealth_fields, user_id]`. `listFields` eager-loads `values` ordered by `date`. Validation: `StoreWealthValueRequest` gets `date` => `sometimes|date_format:Y-m-d|before_or_equal:today`, and a new `UpdateWealthValueRequest` covers `value` + `date`. If an update moves a value to a date that already has a value, return 422 rather than merging silently, and document that choice.
     files: app/Api/Finances/Services/WealthService.php, app/Api/Finances/Requests/StoreWealthValueRequest.php, app/Api/Finances/Requests/UpdateWealthValueRequest.php (new)
     depends: TASK-1 (unique index backs the one-per-day rule)
     risk: medium — the existing `PUT {field}/value` changes from "always insert" to "replace today's value", which old clients will see. This is intended by the brief, and the status code and shape stay the same.

TASK-5: Make `WealthFieldResource::currentValue` the value with the latest `date` (sort explicitly, e.g. `sortBy('date')->last()`, and don't rely on load order), and return `values` ordered by date.
     files: app/Api/Finances/Resources/WealthFieldResource.php
     depends: TASK-4
     risk: low — small change, and the shape stays the same (STANDARDS 13.2).

TASK-6: Expose the wealth value endpoints. Keep `PUT finances/wealth/fields/{field}/value` (1.0.15 calls it; now with optional `date`). Add update and delete for a single value (suggested: `PUT`/`DELETE finances/wealth/fields/{field}/values/{value}`). Each action checks `isResourceOwner` on the field and that the value belongs to that field (STANDARDS 12.3, children checked against parent). Write OpenAPI annotations for new and changed operations, with unique `operationId`s (STANDARDS 2.x).
     files: app/Api/Finances/Controllers/WealthController.php, routes/api.php
     depends: TASK-4
     risk: medium — the ownership check on the nested value is the security-relevant part, and a missing parent check would let a user edit another user's value.

TASK-7: Add budget value history to `BudgetService`: add with optional date (replace on the same date), update and delete a single value. After each change, set `budgets.value` to the latest-dated value (Q-3), handle deleting the last value (Q-1), and forget `[budgets, user_id]`. `create()` also writes the first history value dated today. `update()` with `value` writes or replaces today's value instead of only overwriting the column (Q-2). Add `StoreBudgetValueRequest` / `UpdateBudgetValueRequest` with the same date rules as TASK-4. Keep it inside `BudgetService` (STANDARDS 4.7: sub-resource inside its aggregate).
     files: app/Api/Finances/Services/BudgetService.php, app/Api/Finances/Requests/StoreBudgetValueRequest.php (new), app/Api/Finances/Requests/UpdateBudgetValueRequest.php (new)
     depends: TASK-3
     risk: high — it changes what existing create/update calls do, and the column and the history must never drift apart. Wrap each write in a DB transaction.

TASK-8: Expose the budget value endpoints. Add value history routes, suggested: `GET`/`POST finances/budget/{budget}/values`, `PUT`/`DELETE finances/budget/{budget}/values/{value}`. Register them before the `apiResource('budget', ...)` line so the resource line stays last (STANDARDS 1.4). Check the owner on the budget and the value-to-budget link. Add `values` to `BudgetResource` only via `whenLoaded`, so the existing list/show responses don't change (STANDARDS 6.1, 13.3). Add a `BudgetValue` OpenAPI schema (reuse or mirror `WealthValue`) and annotate the new operations. Update the `PUT /finances/budget/{budget}` description to explain the new meaning of `value`.
     files: app/Api/Finances/Controllers/BudgetController.php, app/Api/Finances/Resources/BudgetResource.php, app/Api/Finances/Resources/BudgetValueResource.php (new), routes/api.php
     depends: TASK-7
     risk: medium — route ordering next to `apiResource`, and the nested ownership check.

TASK-9: Add tests in `app/Api/Finances/Tests` (per docs/TESTING.md). Wealth:
     - add with and without a date
     - a same-day add replaces the existing value
     - a future date gets 422
     - update and delete
     - another user gets 403/404
     - a value from another field is rejected
     - `currentValue` is the latest date after a backfill
     - the cache is invalidated

     Budget:
     - create writes the first value
     - legacy `PUT budget` with `value` replaces today's value and updates `value`
     - add, update and delete keep `value` equal to the latest-dated value
     - deleting the last value behaves as decided in Q-1
     - ownership checks
     - list/show still return `value`

     Update the existing "updateValue creates a value for the field" test to the new replace semantics.
     files: app/Api/Finances/Tests/FinanceApiTest.php, app/Api/Finances/Tests/FinanceTest.php
     depends: TASK-5, TASK-6, TASK-8
     risk: low — tests only.

TASK-10: Add client contract tests that pin the finance fields the 1.0.15 frontend reads: `Budget.value` (number) on list/show, and `WealthField.currentValue`/`values` plus the `PUT wealth/fields/{field}/value` request without `date`. None exist yet, and this job changes what sits behind them (STANDARDS 13.6).
     files: tests/Unit/ClientContractTest.php
     depends: TASK-5, TASK-8
     risk: low — tests only. Check which fields 1.0.15 actually reads against the frontend tag before pinning them. If that can't be checked from this repo, say so in implementation.md rather than guessing.

## Checked, no change expected

- `app/Api/Export/Services/FinanceIncomeExportService.php` reads `budgets.value`. This stays correct if Q-3 keeps the column in sync. Exporting the history is not requested.
- `app/Api/Statistics/Services/StatisticsService.php` counts `Budget` and `WealthValue` rows. Not affected.
- User deletion: `budget_values` cascades via `budgets` → `users`, and the cache keys `budgets`/`wealth_fields` are already in `UserService::USER_CACHE_KEYS`. No new cache key is introduced.
- No `pgsql`/DAV involvement.

## Done criteria

`composer test` and `composer analyse` exit 0. Both migrations were shown before being run. The OpenAPI annotations cover every new and changed endpoint.
