# Backlog: budget items with a quarterly or yearly interval

created: 2026-11-17

## What

Give each budget item (income and expense) an `interval`: `monthly`,
`quarterly` or `yearly`. Today a budget only has `title`, `type` and `value`,
and every value is implicitly monthly.

- **Migration:** add `interval` to `budgets` (string, not null, default
  `monthly`), so existing rows stay correct without a data migration.
  Table created in `database/migrations/2025_09_13_101549_create_finances_tables.php`.
- **Model:** `interval` in `$fillable` of `app/Api/Finances/Models/Budget.php`.
- **Validation:** `StoreBudgetRequest` gets `'interval' => 'sometimes|string|in:monthly,quarterly,yearly'`
  (missing means `monthly`, so older app builds keep working);
  `UpdateBudgetRequest` gets the same rule.
- **Resource:** `BudgetResource` returns `interval`; add it to the
  `@OA\Schema` (enum `monthly`, `quarterly`, `yearly`) and to the request
  docs on `BudgetController`.
- **Factory:** `BudgetFactory` defaults to `monthly`.
- **Tests** in `app/Api/Finances/Tests/`: create without interval gives
  `monthly`; create and update with `quarterly`/`yearly`; an invalid value is
  rejected with 422; the resource includes the field.

The interval belongs to the budget item, not to a history entry: the values
in `budget_values` mean "amount per interval". Changing a budget's interval
therefore reinterprets its whole history. That is intended; document it in
the resource description rather than adding special handling.

The API stores and returns the amount as entered. Converting to a monthly
amount is the app's job (see the app entry `budget-interval`).

## Why

Users with quarterly or yearly costs (insurance, subscriptions) or income
(bonus, dividends) currently have to divide by 3 or 12 by hand and enter a
monthly amount. Dev request, decided 2026-11-17: both income and expenses,
interval per budget item, the budget page stays a monthly view.

## Notes

- `BudgetService::index()` orders by `value` desc
  (`app/Api/Finances/Services/BudgetService.php:26`). With mixed intervals
  that's no longer the monthly order; leave it as is, the app sorts by the
  monthly amount.
- The app entry depends on this one; this one ships first. It's
  backward-compatible: old app builds ignore the field and get the default.
- Check: `composer test` and `composer analyse` pass; `POST /v1/budgets`
  without `interval` returns `"interval": "monthly"`; with `"yearly"` it
  returns `"yearly"`; `"weekly"` returns 422.
