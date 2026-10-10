# Verdict: notebook: journal folders

id: home
reviewer: reviewer
date: 2026-10-10

<!-- Produced by @reviewer and/or @security after implementation. -->

## Review

TASK-1: PASS
notes: `database/migrations/2026_10_10_000000_add_system_and_hidden_to_note_categories.php`
adds both booleans with default `false` after `sort_order`; `down()` drops them.
`after()` is fine on MySQL and ignored on test SQLite.

TASK-2: PASS
notes: `NoteCategory` gains `JOURNAL_TITLE = '.journal'`, both flags in
`$fillable` and boolean `$casts`, model attribute defaults, and the shared
`provisionJournal(User)` helper (idempotent `firstOrCreate` on
user_id + title + null parent). Used by both the observer and the backfill
migration, so the `.journal` definition lives once as required.

TASK-3: PASS
notes: `2026_10_10_000001_backfill_journal_categories.php` iterates
`User::query()->select('id')->chunkById(500)` and calls `provisionJournal` per
user (skip-if-exists via firstOrCreate); `down()` no-ops, which is defensible
since journal folders are user data. Caveat (documented in implementation.md):
the backfill does not invalidate the per-user `note_categories` Redis cache
(24h TTL), so existing users who have a warm cache won't see the journal in
`GET /v1/notes/categories` until it expires. This was not a task requirement,
but a one-off cache clear on deploy is recommended so the feature actually
surfaces for existing users immediately.

TASK-4: PASS
notes: `UserObserver::created` provisions the journal for new users; no
pre-existing cache for a new user, so no invalidation needed there.

TASK-5: PASS
notes: `NoteCategoryController::update()`/`destroy()` abort with 403 for
`is_system` folders after the ownership check. `is_system` is unreachable via
the API: Store/Update requests never validate it, so `validated()` can't carry
it (verified by the new "never lets the API set is_system" test).

TASK-6: PASS
notes: `PATCH categories/{category}/hidden` registered before the `apiResource`;
`UpdateNoteCategoryHiddenRequest` enforces `hidden => required|boolean`;
`updateHidden` enforces the ownership check but allows system folders;
`NoteCategoryService::updateHidden` persists `is_hidden` and invalidates both
the `note_categories` cache and `NoteService`'s `notes_newest` key via the new
`NoteService::forgetNewest()`. No circular injection: `NoteService` depends
only on `UserCacheService`.

TASK-7: PASS
notes: `NoteService::newest()` adds
`whereDoesntHave('category', fn ($q) => $q->where('is_hidden', true))` — a
note with `category_id = null` has no matching category so it survives the
`NOT EXISTS`, keeping uncategorized notes (covered by a test). `list()` is
untouched, so `GET /v1/notes` stays complete per the brief.

TASK-8: PASS
notes: `NoteCategoryResource` exposes `is_system`/`is_hidden` and the
`NoteCategory` OpenAPI schema (which lives on the resource annotation, as
tasks.md notes) documents both properties.

TASK-9: PASS
notes: Factory defaults both flags to `false`; `system()` / `hidden()` states
added.

TASK-10: PASS
notes: Fallout updated correctly — `NoteApiTest` category list roots now 2 and
remaining rows 1; `NoteTest` global `roots()` count now 4 (three factory roots
+ one provisioned journal). `StatisticsTest` was verified: it asserts no exact
`note_folders` count, so no change was needed. New coverage is solid: journal
provisioning (flags, root-level, idempotency), 403 on rename/delete of system
folders, `is_system` non-settable, hidden toggle (ordinary + system folder,
422 validation, cross-user 403), and `newest` excluding hidden-folder notes
while keeping uncategorized ones.

Note: the full test suite could not be re-run in this review session (bash is
restricted to git read/commit); the above is a static review. Developer
reports 0 failures.

## Security

No security findings. Ownership checks (403) guard every mutating category
endpoint including the new hidden toggle; `is_system` is server-only and
unreachable from request payloads; the backfill runs under the app's own DB
credentials. The only cross-cutting consideration is the documented cache
staleness on backfill, which is an availability/visibility concern, not a
security one.

## Overall

APPROVED

Optional (non-blocking) follow-up: invalidate or clear the per-user
`note_categories` cache during/after the backfill migration (or clear Redis on
deploy) so the journal folder appears for existing users immediately rather
than after the 24h TTL.