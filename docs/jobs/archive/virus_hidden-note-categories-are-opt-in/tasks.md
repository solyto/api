# Tasks: Hidden note categories are opt-in

id: virus
analyst: @analyst
date: 2026-10-14

<!-- Produced by @analyst from brief.md. -->

Branch checked: the worktree HEAD is `feature/virus_hidden-note-categories-are-opt-in`, which matches `brief.md`.

## Context found while reading

- `NoteCategoryService` already depends on `NoteService` (constructor, used by `updateHidden` → `forgetNewest`). `NoteService` must **not** get `NoteCategoryService` injected, because that would create a circular dependency. Put shared logic in `NoteService`, or keep it inside `NoteCategoryService` and pass the result through.
- `notes.category_id` has **no foreign key** (`2025_08_26_115219_add_category_id_to_notes.php`). Deleting a category leaves its notes with a dangling `category_id`. `note_categories.parent_id` does cascade. `UpdateNoteCategoryRequest` only accepts `title`, so `parent_id` is set on create only. Category subtrees never move, except by `is_hidden` toggles and creates.
- The "hidden set" is every category with `is_hidden OR is_system`, plus every descendant of one. Nesting depth is unbounded. The simplest correct way to get the set is to load all of the user's categories once (`forUser`) and walk them in PHP.
- Pitfall: `NoteCategory::descendants()` is `children()->with('descendants')`. A constraint passed in `with(['descendants' => fn …])` applies only to the **first** level, and deeper levels come back unfiltered. Filter the visible tree recursively, or build it from the already-loaded flat list.
- `UserService::USER_CACHE_KEYS` lists every per-user key (`note_categories`, `notes`, `notes_newest`) so they get forgotten on user deletion. The new keys belong in that list too.
- `tests/Unit/OpenApiSpecTest.php` fails on route/annotation drift, so the two new routes need annotations or the suite goes red.
- `tests/Unit/ClientContractTest.php` **does not exist yet**. The backlog entry `client-contract-tests-1-0-15` is meant to create it ("`hidden-note-categories-opt-in` adds its own absence test to this file"). See TASK-6 and Open points.

## Task breakdown

TASK-1: Add one shared rule in `NoteService` that returns the user's hidden category ids. It loads the user's categories once, takes the ones with `is_hidden` or `is_system` set, adds all their descendants, and returns the set.
     files: app/Api/Notes/Services/NoteService.php
     depends: none
     risk: medium — every other task uses this rule, and an off-by-one in the descendant walk would leak or wrongly hide folders. It is placed in `NoteService` because `NoteCategoryService` already depends on it, and the reverse would be circular.

TASK-2: Add a `notes_hidden` cache key and `NoteService::listHidden()` (notes whose `category_id` is in the hidden set). Change `list()` to exclude those notes. Change `newest()` from the direct `is_hidden` check to the same hidden-set rule. Uncategorised notes, and notes whose `category_id` points to a deleted category, stay visible, as they are today.
     files: app/Api/Notes/Services/NoteService.php
     depends: TASK-1
     risk: medium — this changes what `GET notes` returns for every client. The existing `newest` test ("excludes notes in hidden folders…") must stay green.

TASK-3: Add a `note_categories_hidden` cache key and `NoteCategoryService::listHidden()`, which returns the left-out categories as a tree. Its roots are the topmost categories in the hidden set (the parent is null or visible), ordered by title, each with its full `descendants`. Change `listRoots()` so that it returns only visible roots and, recursively, only visible children (see the `descendants` pitfall above).
     files: app/Api/Notes/Services/NoteCategoryService.php (possibly app/Api/Notes/Models/NoteCategory.php only if a relation is truly needed; rule 5.1 allows relations, not logic)
     depends: TASK-1
     risk: medium — the recursive eager-load constraint is easy to get subtly wrong, and the plain list is exactly what 1.0.15 reads.

TASK-4: Wire cache invalidation (rules 8.1, 8.2, 4.4):
     - `NoteService` gets one forget method that clears `notes`, `notes_hidden` and `notes_newest`. Make the existing private `forgetUser` cover all three, and replace or extend the public `forgetNewest` so that `NoteCategoryService` can clear all note lists.
     - `NoteCategoryService` gets a private `forgetUser` that clears `note_categories` and `note_categories_hidden`, plus the note lists through `NoteService`. It is called from `create`, `update`, `destroy` and `updateHidden`. `destroy` and `updateHidden` change which notes are hidden. Clearing the note lists on `create`/`update` too follows the brief's "every write … forgets all of them".
     - Add `note_categories_hidden` and `notes_hidden` to `UserService::USER_CACHE_KEYS`.
     files: app/Api/Notes/Services/NoteService.php, app/Api/Notes/Services/NoteCategoryService.php, app/Api/Users/Services/UserService.php
     depends: TASK-2, TASK-3
     risk: low — mechanical, but a missed forget leaves stale lists for up to 24 h (`CACHE_TTL`).

TASK-5: Add `NoteCategoryController::hidden` and `NoteController::hidden` (the usual delegate + `ApiResponse::success` + resource collection) with OpenAPI annotations (section 2: relative path, `operationId` `noteCategoryHidden` / `noteHidden`, envelope via `SuccessResponse`). Register `Route::get('categories/hidden', …)` before `apiResource('categories', …)` and `Route::get('hidden', …)` before `apiResource('/', …)` in the `notes` prefix group.
     files: app/Api/Notes/Controllers/NoteCategoryController.php, app/Api/Notes/Controllers/NoteController.php, routes/api.php
     depends: TASK-2, TASK-3
     risk: low — additive (rule 13.2). Route order is the main trap, and `OpenApiSpecTest` catches missing annotations.

TASK-6: Client contract tests ("Since 1.0.15"). The plain `GET notes/categories` contains no `.journal`, no other hidden/system category and no descendant of one, at any depth. The plain `GET notes` contains no note from any of them. Because the file does not exist yet, create `tests/Unit/ClientContractTest.php` with `uses(RefreshDatabase::class)` and a `describe('Client contract', …)` block, following `docs/TESTING.md` → Client contract tests. Add **only** these absence tests, and leave the route fixture and the other field tests to the `client-contract-tests-1-0-15` entry.
     files: tests/Unit/ClientContractTest.php (new)
     depends: TASK-2, TASK-3
     risk: medium — creating the file here may conflict with the separate client-contract job if both are in flight (see Open points). The header comment needs a real `solyto/app` 1.0.15 file:line for the notes navigation, which the developer must look up read-only.

TASK-7: Notes API tests in `app/Api/Notes/Tests/NoteApiTest.php`:
     - `GET notes/categories/hidden` returns `.journal`, user-hidden folders and their descendants as a tree, and no visible folder.
     - `GET notes/hidden` returns exactly the notes in those folders.
     - `newest` excludes notes in a visible child of a hidden folder (the new rule).
     - Tenant isolation: another user's hidden categories and notes never appear.
     - Cache: prime both plain and hidden lists, `PATCH categories/{category}/hidden`, then assert that all four lists reflect the change.
     - `hidden` is not bound as `{category}` / `{note}` (the route returns 200, not 404).
     files: app/Api/Notes/Tests/NoteApiTest.php
     depends: TASK-4, TASK-5
     risk: low — test-only. Existing tests that list categories (`Note categories CRUD`) may need adjusting only if they relied on `.journal` appearing. Check that rather than assuming it.

TASK-8: Run `composer test` and `composer analyse` in the worktree, and make sure both exit 0.
     files: none
     depends: TASK-1 … TASK-7
     risk: low — verification only.

## Open points (not blocking; the developer should note the outcome in implementation.md)

1. **ClientContractTest.php does not exist.** The brief says to add tests "in" it. TASK-6 creates the file with only this job's tests. If the `client-contract-tests-1-0-15` job lands first or in parallel, merge into its file instead of creating a second one.
2. **Deleting a user-hidden folder.** Because `notes.category_id` has no FK, the notes of a deleted hidden folder end up with a dangling `category_id` and will reappear in the plain `GET notes`. This matches today's orphan behaviour and `.journal` can't be deleted (403), so this plan does not change it. Flagged only so nobody is surprised.
3. **`NoteImportService` writes without forgetting any cache.** It saves categories and notes directly, which is a pre-existing gap for `note_categories`/`notes` as well. The brief doesn't name it, so it is **not** in scope here. It is a candidate backlog entry.
4. **Note writes vs. category caches.** Note create/update/destroy don't change either category list (the category resource holds no notes), so TASK-4 has note writes forget only the three note keys. If the owner meant "every write forgets all five keys", extending that is trivial.
