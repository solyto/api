# Verdict: Hidden note categories are opt-in

id: virus
reviewer: @reviewer
date: 2026-10-14

## Review

Branch `feature/virus_hidden-note-categories-are-opt-in` checked. Reviewed against `git diff main...HEAD`. Every changed source file is listed in implementation.md. Nothing outside the task list changed.

TASK-1: PASS
notes: `NoteService::hiddenCategoryIds` loads the user's categories once and seeds from `is_hidden || is_system`. It walks the children with a visited set, so it reaches any depth and cannot loop. It is placed in `NoteService` to avoid a circular dependency.

TASK-2: PASS
notes: `list()` and `newest()` share `whereVisible()`, which is `category_id IS NULL OR category_id NOT IN (…)`. Uncategorised notes and dangling `category_id`s stay visible. With an empty hidden set, Laravel compiles `whereNotIn([])` to `1 = 1` and `whereIn([])` to `0 = 1`, so both cases are correct. The `listHidden()` and `notes_hidden` keys are added. The existing newest/hidden test is still green.

TASK-3: PASS
notes: `tree()` builds the visible or hidden tree from one flat load and sets `descendants` from the filtered set, which avoids the first-level-only `with()` pitfall. Roots are nodes whose parent is null or not in the kept set. This matches the plan: a hidden child of a visible folder is a root of the hidden tree. `NoteCategoryResource` is unchanged. Small difference, not a blocker: when children have the same `sort_order`, ties are now broken by title instead of DB order.

TASK-4: PASS
notes: `NoteService::forgetUser` clears `notes`, `notes_hidden` and `notes_newest`. `forgetNewest` became `forgetLists`, and it had no other callers (grep). `NoteCategoryService::forgetUser` clears both category keys plus the note lists, and it is called from `create`, `update`, `destroy` and `updateHidden`. The two new keys are added to `UserService::USER_CACHE_KEYS`.

TASK-5: PASS
notes: Both controller methods follow the delegate + `ApiResponse::success` + resource collection pattern. They have OpenAPI annotations with relative paths, `operationId`s `noteCategoryHidden`/`noteHidden` and a `SuccessResponse` envelope. In `routes/api.php`, both routes are registered before their `apiResource`s, and the 200 responses in the tests confirm it. `OpenApiSpecTest` is green.

TASK-6: PASS
notes: New `tests/Unit/ClientContractTest.php` with "Since 1.0.15" absence tests for categories (journal child, hidden at depth 3, hidden child of a visible folder) and notes (journal, hidden, hidden child; uncategorised notes and notes in visible folders are kept). The file was created because it did not exist yet (open point 1, noted in implementation.md).

TASK-7: PASS
notes: Covers the hidden tree, exact hidden notes, newest with a visible child of a hidden folder, tenant isolation, and cache invalidation across all five lists after `PATCH …/hidden`. The `Note categories CRUD` count changed from 2 to 1, which is expected now that `.journal` is hidden.

TASK-8: PASS
notes: The reviewer re-ran both in the worktree. `composer test`: exit 0, 784 tests, 2244 assertions, no failures. The warnings are `file_get_contents` on the missing `/workspace/.env`, an environment issue. `composer analyse`: exit 0, "No errors".

## Security

Not run separately. Tenant isolation: every query is scoped with `forUser($userId)`, and a test covers it.

## Overall

APPROVED

No blockers. Reminder from the brief, not a code change: ship together with the `solyto/app` change that loads `notes/categories/hidden` and `notes/hidden`, before the next frontend release.
