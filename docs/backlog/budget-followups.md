# Backlog: budget follow-ups (tags cache, duplicate tags, export)

created: 2026-11-18

## What

Three small gaps around the budget intervals and budget tags added after 1.1.3.

1. **Stale tags in the budget list.** `TagService` (`app/Api/Tags/Services/TagService.php`,
   rename/delete around 42–58) only clears the `tags` cache, not `budgets_v2`.
   The budget list caches tags for 24 h (`BudgetService.php:27`). After renaming
   a tag the budget page shows the old name for up to a day; after deleting one,
   the tag filter still offers it. (Notes, books and todos have the same gap
   from before; budgets just make it visible through the filter.)
2. **Duplicate tag ids give a 500 on create.** `StoreBudgetRequest` has no
   `distinct` rule for `tags.*`, so `tags: [3, 3]` makes `attach()` violate the
   unique key on `tag_budget`. Update uses `sync()` and is fine.
3. **The income CSV export ignores `interval`**
   (`app/Api/Export/Services/FinanceIncomeExportService.php:18`). A yearly item
   of 12000 exports as "12000" with no hint that it is yearly.

## Suggestion

1. Clear `budgets_v2` for the tag's owner on tag rename and delete (or clear
   all tag-carrying list caches there in one place).
2. Add `distinct` to `tags.*` in `StoreBudgetRequest` (and `UpdateBudgetRequest`
   for consistency). Test: duplicate ids → 422.
3. Add an `interval` column to the export.

Check: `grep -n "forget" app/Api/Tags/Services/TagService.php`
