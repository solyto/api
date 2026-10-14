# Implementation: Hidden note categories are opt-in

id: virus
developer: @developer
date: 2026-10-14

## Summary

The plain notes endpoints now return only visible folders and their notes. A category is "hidden" if it has `is_hidden` or `is_system` set, or if it is a descendant of such a category at any depth. That rule is defined once, in `NoteService::hiddenCategoryIds`. The hidden folders and their notes come from two new endpoints, `GET notes/categories/hidden` and `GET notes/hidden`, which use the same resources as the plain lists. Each list has its own `UserCacheService` key. Every category write forgets all five note/category lists, and every note write forgets the three note lists. `composer test` (784 tests) and `composer analyse` both exit 0.

## Changes

TASK-1: `app/Api/Notes/Services/NoteService.php`: `hiddenCategoryIds(string $userId)` loads the user's categories once (`id`, `parent_id`, `is_hidden`, `is_system`). It starts from the flagged categories and walks down to all their descendants with a visited set, then returns the ids. It lives in `NoteService` because `NoteCategoryService` already depends on it (the reverse would be circular).

TASK-2: `NoteService.php`:
- New `notes_hidden` key and `listHidden()` (notes whose `category_id` is in the hidden set).
- `list()` and `newest()` share a private `whereVisible()`, which is `category_id IS NULL OR category_id NOT IN (hidden)`. The explicit `IS NULL` branch matters: SQL `NOT IN` never matches NULL, so without it uncategorised notes would vanish.
- `newest()` used to check `is_hidden` on the direct category only. It now uses the full rule.

TASK-3: `app/Api/Notes/Services/NoteCategoryService.php`:
- New `note_categories_hidden` key and `listHidden()`.
- `listRoots()` and `listHidden()` share a private `tree()`. It loads the user's categories once, ordered by title, and keeps either the visible or the hidden ones. Each node's `descendants` relation is set from that filtered list, with children sorted by `sort_order`. Roots are the nodes whose parent is null or outside the kept set. This sidesteps the pitfall where `with(['descendants' => …])` only filters the first level. `NoteCategoryResource` and its `children` output are unchanged.

TASK-4: Cache invalidation (rules 8.1, 8.2, 4.4):
- `NoteService`: the private `forgetUser` now forgets `notes`, `notes_hidden` and `notes_newest`. The public `forgetNewest` is replaced by `forgetLists()`, which delegates to it, so `forgetUser` stays private as rule 4.4 asks.
- `NoteCategoryService`: a new private `forgetUser` forgets both category keys plus the note lists via `forgetLists`. It is called from `create`, `update`, `destroy` and `updateHidden`.
- `app/Api/Users/Services/UserService.php`: `note_categories_hidden` and `notes_hidden` are added to `USER_CACHE_KEYS`, and the list is re-wrapped.

TASK-5: `NoteCategoryController::hidden` and `NoteController::hidden` are added with OpenAPI annotations (`noteCategoryHidden`, `noteHidden`, relative paths, `SuccessResponse` envelope). In `routes/api.php`, `categories/hidden` is registered before `apiResource('categories', …)` and `hidden` before `apiResource('/', …)`. `route:list` confirms the order.

TASK-6: New file `tests/Unit/ClientContractTest.php` (`uses(RefreshDatabase::class)`, `describe('Client contract')`). It holds only this job's two absence tests:
- The plain category list contains no `.journal`, no hidden folder and no descendant of one at any depth, including a hidden child of a visible folder.
- The plain notes list contains no note from any of them.

The headers cite the real 1.0.15 lines: `solyto/app src/lib/state/Notes.svelte.ts:83-84` (categories) and `:53-56` (notes). I found them in a read-only download of the `1.0.15` tag into `/tmp`, which I have since deleted.

TASK-7: `app/Api/Notes/Tests/NoteApiTest.php`, new `describe('Hidden notes and categories')`:
- The hidden category tree, including depth 3 and a hidden child of a visible folder.
- `notes/hidden` returns exactly the hidden notes.
- `newest` excludes notes in a visible child of a hidden folder.
- Tenant isolation.
- Cache: all five lists are primed, then `PATCH …/hidden` is sent, and all five reflect the change.

The two new routes returning 200 also shows that `hidden` isn't being bound as `{category}`/`{note}`. One existing assertion, `Note categories CRUD`, expected 2 categories because `.journal` used to show up in the plain list. It now expects 1, with a comment.

TASK-8: `composer test`: 0 failures. `composer analyse`: no errors.

## Known issues / follow-ups

- **Open point 1:** `ClientContractTest.php` didn't exist, so this job created it with only its own tests. If the `client-contract-tests-1-0-15` job lands too, merge the two files rather than keeping two.
- **Open point 2 (unchanged behaviour):** `notes.category_id` has no foreign key. If a user deletes a user-hidden folder, its notes keep a dangling `category_id` and reappear in the plain `GET notes`. This doesn't affect `.journal`, because deleting it returns 403.
- **Open point 3 (not in scope):** `NoteImportService` writes categories and notes without forgetting any cache. That gap existed before this job; it's a candidate backlog entry.
- **Open point 4:** note writes forget only the three note keys, since the category resource contains no notes. Category writes forget all five.
- **Frontend coordination (from the brief):** `solyto/app` `main` has to switch to `notes/categories/hidden` and `notes/hidden` before its next release, and the two need to be deployed together.
- **Environment, nothing committed:** `vendor/` was missing, so I ran `composer install` from the lock file (no new packages). `storage/framework/*` and `storage/app/public/user` were also missing; I created them locally, and they're gitignored. Without that directory, two Export tests fail on `fopen`. The test run shows warnings because there is no `/workspace/.env`. `.env.testing` shows as modified in `git status`, but it is a character-device mount that git can't hash. I didn't touch it and it isn't committed.
