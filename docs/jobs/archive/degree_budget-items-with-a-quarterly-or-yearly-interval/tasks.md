# Tasks: budget items with a quarterly or yearly interval

Branch verified: `feature/degree_budget-items-with-a-quarterly-or-yearly-interval`.

## Notes for the developer

- The real route is `/api/v1/finances/budget` (not `/v1/budgets` as written in
  the brief's check list). Use it in tests.
- `BudgetService::create()` returns the in-memory model from `Budget::create()`;
  Eloquent does not read DB column defaults back. Without a model-side default,
  `POST` without `interval` would return `"interval": null`. Hence the
  `$attributes` default in TASK-2 (same pattern as `NoteCategory::$attributes`).
- `BudgetService::update()` already forwards everything except `value` to
  `$budget->update()`, so `interval` needs no service change. Do not change
  the `orderByDesc('value')` in `list()` (brief, Notes).
- The migration is purely additive (`string` + `default`), so
  `tests/Unit/MigrationCompatibilityTest.php` will not flag it; no allowlist
  entry needed. Shape: `2026_05_20_000001_add_link_to_todos.php` (STANDARDS 12.6).
- Rule 13.3 ("new enum values in lists" are opt-in) is knowingly overridden by
  the brief: old builds will list quarterly/yearly items as if monthly. The
  brief decides this is acceptable; reviewer should not treat it as a defect.

## TASK-1 — Migration adding `budgets.interval`

Add a new migration that adds `interval` (string, not null, default
`monthly`, after `type`) to `budgets`, with a `down()` that drops it.

- Files: `database/migrations/2026_11_17_000001_add_interval_to_budgets.php` (new)
- Depends on: —
- Risk: low — additive column with a default; existing rows become `monthly` with no data migration.

## TASK-2 — Model: fillable + default

Add `interval` to `Budget::$fillable` and add
`protected $attributes = ['interval' => 'monthly'];` so freshly created
models return the default without a reload.

- Files: `app/Api/Finances/Models/Budget.php`
- Depends on: TASK-1
- Risk: low — mirrors an existing pattern (`NoteCategory`).

## TASK-3 — Validation rules

Add `'interval' => 'sometimes|string|in:monthly,quarterly,yearly'` to both
`StoreBudgetRequest` and `UpdateBudgetRequest`.

- Files: `app/Api/Finances/Requests/StoreBudgetRequest.php`, `app/Api/Finances/Requests/UpdateBudgetRequest.php`
- Depends on: —
- Risk: low — optional field; existing clients that omit it are unaffected.

## TASK-4 — Factory default

`BudgetFactory::definition()` sets `'interval' => 'monthly'`.

- Files: `app/Api/Finances/Factories/BudgetFactory.php`
- Depends on: TASK-1
- Risk: low — keeps existing tests' assumptions (implicitly monthly).

## TASK-5 — Resource + OpenAPI

Return `interval` from `BudgetResource::toArray()`; add an
`@OA\Property(property="interval", type="string", enum={"monthly","quarterly","yearly"})`
to the `Budget` schema whose description states that `value` and every
history value are amounts per interval, and that changing the interval
reinterprets the whole history. Add the optional `interval` property (same
enum, default `monthly` on store) to the request bodies of `store` and
`update` in `BudgetController`.

- Files: `app/Api/Finances/Resources/BudgetResource.php`, `app/Api/Finances/Controllers/BudgetController.php`
- Depends on: TASK-2
- Risk: low — additive response field (STANDARDS 13.2); annotation-only controller change.

## TASK-6 — Tests

In `app/Api/Finances/Tests/FinanceApiTest.php`, add:
- `POST /api/v1/finances/budget` without `interval` → 201, `data.interval` = `monthly` (and persisted);
- `POST` with `quarterly` and with `yearly` → returned and persisted;
- `PUT /api/v1/finances/budget/{id}` changing `interval` → returned and persisted, `value` untouched;
- `POST` and `PUT` with `weekly` → 422 with an `interval` validation error;
- `GET` list/show include `interval`.

Then run `composer test` and `composer analyse`; both must exit 0.

- Files: `app/Api/Finances/Tests/FinanceApiTest.php`
- Depends on: TASK-1 … TASK-5
- Risk: low — follows existing tests in the same file.
