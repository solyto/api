# Tasks: tags on budget items

id: romantic
analyst: analyst
date: 2026-11-17

<!-- Produced by @analyst from brief.md. -->

## Task breakdown

TASK-1: Add a `tag_budget` pivot migration (`id`, `foreignId('budget_id')` → `budgets.id` cascade, `foreignId('tag_id')` → `tags.id` cascade, unique `[budget_id, tag_id]`, timestamps), modelled on `2025_09_10_174406_add_tags_to_links.php` but with a bigint `budget_id` instead of a uuid.
  files: database/migrations/2026_11_17_000002_create_tag_budget_table.php (new; date after `2026_11_17_000001_add_interval_to_budgets.php`)
  depends: none
  risk: low — new table only on the default (MariaDB) connection, no existing data touched; show the migration before running it (hard rule).

TASK-2: Add `Budget::tags()` as a `BelongsToMany<Tag, $this>` on `tag_budget` (`budget_id`, `tag_id`) with `->withTimestamps()`, like `LibraryLink::tags()`, with the generic PHPDoc return type the other relations in `Budget.php` use.
  files: app/Api/Finances/Models/Budget.php
  depends: TASK-1
  risk: low — additive relation; only risk is a missing/incorrect generic annotation failing `composer analyse`.

TASK-3: Validate `tags` on store and update: `StoreBudgetRequest` gets `'tags' => 'nullable|array'`, `UpdateBudgetRequest` gets `'tags' => 'sometimes|nullable|array'`, both `'tags.*' => [Rule::exists('tags', 'id')->where('user_id', $this->user()->id)]` (as in `Todos/Requests/StoreTodoRequest.php:22-23`).
  files: app/Api/Finances/Requests/StoreBudgetRequest.php, app/Api/Finances/Requests/UpdateBudgetRequest.php
  depends: none
  risk: low — copies an existing rule; the user scope in `Rule::exists` is the whole cross-user protection, so it must not be dropped.

TASK-4: In `BudgetService`, attach `tags` on create (inside the existing transaction) when present, sync them on update when `array_key_exists('tags', $data)` (so `tags: []` and `tags: null` clear them — check `sync(null)` handling, coerce to `[]`), keep `tags` out of the `Budget::create`/`$budget->update(...)` attribute arrays (extend the existing `except('value')`), and eager-load `tags` in `list()` (inside the cached query), `create()` and `update()` (`fresh(['user', 'tags'])`).
  files: app/Api/Finances/Services/BudgetService.php
  depends: TASK-2, TASK-3
  risk: medium — `list()` is cached per user for 24 h, so the eager-load must be inside the `remember` callback and every tag write on a budget must go through the existing `forget`; getting this wrong shows stale tags.

TASK-5: Make `GET /finances/budget/{budget}` return tags: load `tags` on the bound budget before building the resource (preferably via a small `BudgetService::find()` that does `$budget->load('tags')`, mirroring `TodoService::find()`/`TagService::find()`, rather than loading in the controller).
  files: app/Api/Finances/Services/BudgetService.php, app/Api/Finances/Controllers/BudgetController.php
  depends: TASK-2
  risk: low — read-only change on one endpoint; `find()` is part of the standard service shape (`list/find/create/update/destroy`, STANDARDS §4).

TASK-6: `BudgetResource` returns `'tags' => $this->whenLoaded('tags', fn () => TagResource::collection($this->tags))`; add `tags` (array of `#/components/schemas/Tag`, only when loaded) to the `Budget` `@OA\Schema`, and add a `tags` property (array of integer tag ids, nullable) to the store and update `@OA\RequestBody` in `BudgetController` (update description mentions that `tags` replaces the item's tags, `[]` removes them).
  files: app/Api/Finances/Resources/BudgetResource.php, app/Api/Finances/Controllers/BudgetController.php
  depends: TASK-2
  risk: low — additive response field; older clients ignore it. Check the Tag schema name used by `TagResource` before referencing it.

TASK-7: Pest tests in `app/Api/Finances/Tests/FinanceApiTest.php` (new `describe('Budget tags', ...)`): create with own tags returns them; update with tags replaces them; update with `tags: []` removes them; update without `tags` leaves them untouched; another user's tag id → 422 on store and update; deleting a tag removes its pivot row (asserted via `$budget->tags()` / DB, see open question 2); deleting a budget removes its pivot rows; the index and show return `tags`.
  files: app/Api/Finances/Tests/FinanceApiTest.php
  depends: TASK-1, TASK-2, TASK-3, TASK-4, TASK-5, TASK-6
  risk: low — tests only; then `composer test` and `composer analyse` must both exit 0.

## Notes / open questions

1. The brief's check says `POST /v1/budgets`; the actual route is
   `POST /api/v1/finances/budget` (`FinanceApiTest.php:14`). No route change is
   planned — the check should be run against the existing route.
2. Stale tags in the cached budget list: `BudgetService::list()` is cached
   per user for 24 h, but `TagService::update/destroy` only forget the `tags`
   key. After renaming or deleting a tag, `GET /finances/budget` keeps
   returning the old tag until a budget write clears the cache. STANDARDS 8.2
   ("every write forgets every key its reads use") would call for
   `TagService` to also forget `budgets`, but that crosses modules and the
   same gap already exists for todos (`todos` key). Not in the brief, so not
   planned here. Someone should decide whether to fix it in this job or track
   it in `docs/backlog/`.
3. No user-facing strings change, so no translator handoff.
