# Implementation: budget items with a quarterly or yearly interval

id: degree
developer: developer agent
date: 2026-11-17

## Summary

Budget items now have an `interval` (`monthly`, `quarterly`, `yearly`),
defaulting to `monthly` so existing rows and older app builds are unaffected.
The API stores and returns amounts as entered; no conversion happens server-side.
`composer test` and `composer analyse` both exit 0.

## Changes

TASK-1: `database/migrations/2026_11_17_000001_add_interval_to_budgets.php` (new) adds `budgets.interval` (string, not null, default `monthly`, after `type`); `down()` drops it. Not run against a real database.
TASK-2: `app/Api/Finances/Models/Budget.php` — `interval` in `$fillable`, plus `$attributes = ['interval' => 'monthly']` so a freshly created model returns the default without a reload.
TASK-3: `StoreBudgetRequest` / `UpdateBudgetRequest` — `'interval' => 'sometimes|string|in:monthly,quarterly,yearly'`.
TASK-4: `BudgetFactory::definition()` sets `'interval' => 'monthly'`.
TASK-5: `BudgetResource` returns `interval`; `Budget` schema documents it (enum + note that `value` and all history values are amounts per interval and that changing the interval reinterprets the history). `BudgetController` store/update request bodies document the optional `interval` (default `monthly` on store); update description mentions interval.
TASK-6: `app/Api/Finances/Tests/FinanceApiTest.php` — new `Budget interval` block: default `monthly` on create (response + DB), create with `quarterly`/`yearly`, update interval leaves value and history untouched, `weekly` rejected with 422 on POST and PUT, list/show include the field.

## Known issues / follow-ups

- `app/Api/Contacts/Tests/ContactApiTest.php:110` failed once in a full-suite run and passed on rerun: it compares `pluck('has_photo', 'uri')` with `toBe([...])`, which depends on list order. Unrelated to this job.
- Every test emits a PHPUnit warning because the worktree has no `/workspace/.env` (phpdotenv `file_get_contents`); environmental, not caused by this change.
