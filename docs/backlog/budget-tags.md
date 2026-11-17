# Backlog: tags on budget items

created: 2026-11-17

## What

Let budget items (income and expense) carry the user's existing global tags
(`app/Api/Tags`), the same way todos, notes and library items do.

- **Migration:** a `tag_budget` pivot (`budget_id` → `budgets.id`,
  `tag_id` → `tags.id`, both `onDelete('cascade')`, unique pair,
  timestamps). Template: `database/migrations/2025_09_10_174406_add_tags_to_links.php`;
  `budgets.id` is a bigint, not a uuid.
- **Model:** `Budget::tags()`, a `BelongsToMany` on `tag_budget` with
  timestamps (like `LibraryLink::tags()`).
- **Validation:** `StoreBudgetRequest` gets `'tags' => 'nullable|array'` and
  `'tags.*' => [Rule::exists('tags', 'id')->where('user_id', $this->user()->id)]`;
  `UpdateBudgetRequest` the same with `sometimes|nullable|array`
  (as in `Todos/Requests/StoreTodoRequest.php:22-23`).
- **Service:** `BudgetService` attaches tags on create and syncs them on
  update when `tags` is present (like `TodoService`, `:159-160` and
  `:196-197`); `index()` and the single-item responses eager-load `tags`.
- **Resource:** `BudgetResource` returns `tags` via `whenLoaded`
  (`TagResource::collection`), and the `@OA\Schema` and request docs list the
  field.
- **Tests** in `app/Api/Finances/Tests/`: create and update with tags; update
  with `tags: []` removes them; a tag of another user is rejected with 422;
  deleting a tag removes it from the budget; deleting a budget removes its
  pivot rows; the index returns the tags.

No statistics endpoint: the app already loads all budget items and filters
them itself (see the app entry `budget-tags`).

## Why

To see income and expenses for one area of the budget on their own, e.g.
donations for solidarity work against the server costs for it. Dev request
from the maintainer, decided 2026-11-17: use the existing global tags (no
separate finance categories), a filtered monthly view only, one tag at a
time.

## Notes

- Independent of `budget-interval`; either can ship first.
- Older app builds don't send `tags`, so nothing changes for them.
- Check: `composer test` and `composer analyse` pass; `POST /v1/budgets`
  with `"tags": [<own tag id>]` returns the tag; with another user's tag id it
  returns 422.
