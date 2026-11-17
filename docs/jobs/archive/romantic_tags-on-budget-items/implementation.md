## Summary

Budget items (income and expense) can carry the user's global tags. Store
attaches them, update replaces them when `tags` is sent (`[]`/`null` clears
them), and index, show, store and update return them as `tags`.

## Changes

TASK-1: `database/migrations/2026_11_17_000002_create_tag_budget_table.php` — new `tag_budget` pivot (bigint `budget_id` → `budgets.id`, `tag_id` → `tags.id`, both cascade, unique pair, timestamps). Not run against any real database; only the in-memory SQLite test DB.
TASK-2: `Budget::tags()` `BelongsToMany<Tag, $this>` on `tag_budget` with timestamps (`app/Api/Finances/Models/Budget.php`).
TASK-3: `tags` / `tags.*` rules (user-scoped `Rule::exists`) in `StoreBudgetRequest` and `UpdateBudgetRequest`.
TASK-4: `BudgetService` — attach on create inside the transaction, sync on update when `tags` is present (null → `[]`), `tags` kept out of the model attribute arrays, `tags` eager-loaded in the cached `list()`, `create()` and `update()`.
TASK-5: `BudgetService::find()` loads `tags`; `BudgetController::show()` uses it.
TASK-6: `BudgetResource` returns `tags` via `whenLoaded` (`TagResource::collection`); `Budget` schema and store/update request bodies document `tags`.
TASK-7: `describe('Budget tags')` in `app/Api/Finances/Tests/FinanceApiTest.php` — create, replace, clear with `[]`, untouched when omitted, foreign tag 422 on store and update, tag deletion and budget deletion cascade the pivot, index and show return tags.

`composer test` and `composer analyse` both exit 0.

## Known issues / follow-ups

- Stale tags in the cached budget list (tasks.md open question 2): renaming or deleting a tag doesn't forget the `budgets` cache key, so `GET /finances/budget` can show the old tag for up to 24 h until a budget write. Same gap exists for todos; left out of scope, needs a decision / backlog entry.
- Pre-existing flaky test, unrelated to this job: `ContactApiTest` › "Contact photos › it flags contacts with a photo in the list…" compares `pluck('has_photo', 'uri')` with `toBe`, which depends on key order of random URIs; it failed in roughly 2 of 6 isolated runs.
