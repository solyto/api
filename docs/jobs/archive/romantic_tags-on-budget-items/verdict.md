# Verdict: tags on budget items

id: romantic
reviewer: reviewer
date: 2026-11-17

## Review

Reviewed `git diff main...HEAD` on `feature/romantic_tags-on-budget-items`
against brief.md, tasks.md, implementation.md and docs/STANDARDS.md.
Ran the full suite: `composer test` (1727 tests, 0 failures, exit 0) and
`composer analyse` (`[OK] No errors`, exit 0). The tests show
`file_get_contents(/workspace/.env)` warnings because the worktree has no
`.env`. That comes from the environment, not from this job.

TASK-1: PASS
notes: `2026_11_17_000002_create_tag_budget_table.php` matches the
`add_tags_to_links` template, with a bigint `foreignId('budget_id')`. Both
foreign keys cascade on delete, the `[budget_id, tag_id]` pair is unique, and
the table has timestamps. It is additive and uses the default connection only
(STANDARDS 12.6).

TASK-2: PASS
notes: `Budget::tags()` is a `BelongsToMany<Tag, $this>` on `tag_budget` with
`withTimestamps()`, the same as `LibraryLink::tags()`.

TASK-3: PASS
notes: Store uses `nullable|array` and update uses `sometimes|nullable|array`.
Both check `tags.*` with `Rule::exists('tags','id')->where('user_id', …)`, so
only the user's own tags pass (STANDARDS 1.2 / 3.2).

TASK-4: PASS
notes: Create attaches the tags inside the transaction. Update syncs them
only when the `tags` key is sent, and `null` becomes `[]`. `tags` is left out
of the model attribute arrays. `list()` eager-loads `tags` inside the
`remember` callback. Create and update reload with `['user','tags']`, and
every write still clears the `budgets` cache key.

TASK-5: PASS
notes: `BudgetService::find()` loads `tags`, and
`BudgetController::show()` uses it.

TASK-6: PASS
notes: `BudgetResource` returns `tags` through `whenLoaded` +
`TagResource::collection`. The `Budget` schema points to
`#/components/schemas/Tag`, which is the schema name in `TagResource`. The
store and update request bodies document `tags`, and the update description
says that `[]` or `null` clears them.

TASK-7: PASS
notes: The `Budget tags` describe block covers everything the brief and task
asked for: create, replace, clear with `[]`, unchanged when `tags` is not
sent, another user's tag rejected with 422 on both store and update, cascade
when a tag is deleted, cascade when a budget is deleted, and tags returned by
index and show. All 8 tests pass.

Scope: the diff only touches the files listed in tasks.md and the job docs.
Nothing unexpected changed. The stale-tag cache gap (tasks.md open
question 2) is out of scope by design and is recorded in implementation.md
as a follow-up. It does not block this job.

## Security

Not run separately. The diff keeps the user scope on tag ids, and a test
covers it.

## Overall

APPROVED

Nothing must change before merge.
