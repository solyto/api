# Tasks: clipboard files left behind

id: sure
analyst: analyst
date: 2026-11-23

## Task breakdown

TASK-1: Make the expiry job delete only the rows it collected and whose files it already removed, by deleting by the collected ids instead of re-running the `created_at < now()->subDay()` query.
  files: app/Api/Clipboard/Jobs/DeleteOverdueClipboardEntries.php, app/Api/Clipboard/Services/ClipboardService.php, app/Api/Users/Tests/JobTest.php
  notes:
  - Compute the cutoff once. Load the overdue rows (`id`, `user_id`, `file_path`), delete their files through `ClipboardFileService`, then `Clipboard::whereKey($ids)->delete()`.
  - Put the logic in a new `ClipboardService::deleteOverdue()` method and have the job delegate to it. That keeps the `clipboard` cache key private to its owning service (rule 8.1), which TASK-2 needs. Collaborators stay injected (rule 4.2). Keep the job's `handle()` signature callable through `app()->call` the way the existing tests use it.
  - Chunk by id (`chunkById`) if that fits naturally. Entries carry up to 10 MB files, but the row count per day is what matters. Don't add other new structure.
  - Test: a row whose `created_at` is just inside the window when rows are collected must keep both its row and its file. One option is to freeze time with `$this->travelTo()` for the select and advance it before the delete, or to assert the delete uses ids. If a race test can't be written deterministically, a test that the delete runs by id is enough. Say which one in implementation.md.
  depends: none
  risk: low — small, local change to one scheduled job; the existing JobTest cases cover the happy path.

TASK-2: Clear the clipboard list cache for each user who had entries expire, so expired entries stop showing (and stop returning 404 on download).
  files: app/Api/Clipboard/Services/ClipboardService.php, app/Api/Users/Tests/JobTest.php (or app/Api/Clipboard/Tests/ClipboardTest.php)
  notes:
  - In `deleteOverdue()`, after the rows are deleted, call `$this->cache->forget([self::CACHE_KEY, $userId])` once for each distinct `user_id` among the deleted rows (rule 8.2). If it ends up the same line as the other forgets, follow rule 4.4 for its shape.
  - Test: warm the cache with `ClipboardService::list()` (or a GET `/api/v1/clipboard`), backdate the entry, run the job, then assert the list no longer contains the entry. Also assert that another user's cached list isn't affected.
  depends: TASK-1
  risk: low — uses the existing `UserCacheService::forget`; worst case is one extra cache miss.

TASK-3: In `2026_11_16_120000_add_file_type_to_clipboards::down()`, delete the stored files of `type = 'file'` rows from the `private_user_data` disk before deleting the rows.
  files: database/migrations/2026_11_16_120000_add_file_type_to_clipboards.php, app/Api/Clipboard/Tests/ClipboardApiTest.php (or a new test next to the existing migration test)
  notes:
  - Use `Storage::disk('private_user_data')` directly, the same way the sibling move migration does, instead of `ClipboardFileService`. That keeps app classes out of migrations: the compatibility test flags static app-class calls in `up()`, and the same reasoning applies here. Pluck the non-null `file_path`s, delete them, then delete the rows.
  - Only `down()` changes. `MigrationCompatibilityTest` scans `up()` only, so the allowlist entry needs no change.
  - Test: fake the disk, create a `file` row with a stored file, call `down()` with the same `require database_path(...)` pattern as the existing move-migration test, and assert the file is gone. Caveat: on SQLite in memory, `down()` also runs `->change()`/`dropColumn` on `clipboards`. If that breaks the test schema for later tests or fails on SQLite, report it instead of working around it. Testing only the file-delete part through a private helper is acceptable.
  depends: none
  risk: medium — it edits a migration that may already be deployed (dated 2026-11-16); changing `down()` is harmless to a migrated DB, but it must not alter `up()`.

TASK-4: In `2026_11_16_120001_move_clipboard_images_to_private_disk`, log every image that can't be moved: source missing, unreadable stream, or failed write.
  files: database/migrations/2026_11_16_120001_move_clipboard_images_to_private_disk.php, app/Api/Clipboard/Tests/ClipboardApiTest.php
  notes:
  - Log a `warning` with the path and the from/to disk names only, never file contents (rule 4.9 and secrets hygiene). Use the default channel unless a better-fitting existing channel turns up; don't add a new channel.
  - Decide whether "source missing" should be logged too. The brief only says "failed move", but a missing source is exactly the case the existing test (`gone.png`) shows silently 404s later. Recommendation: log it. Flag it in implementation.md if the developer disagrees.
  - Don't change the move behaviour itself: no retry, and no fallback to reading from the public disk. That would be scope creep.
  - Test: extend or add to "moves existing images to the private disk" with `Log::spy()` / `Log::shouldReceive('warning')` to assert the `gone.png` row logs a warning and the successful move doesn't.
  depends: none
  risk: low — log-only change; note that if this migration has already run in production, the new logging only helps future runs or rollbacks (`down()` uses the same `move()`), not past ones.

TASK-5: Run `composer test` and `composer analyse` in the worktree; both must exit 0.
  files: none
  depends: TASK-1, TASK-2, TASK-3, TASK-4
  risk: low — verification only.

## Open points (not blocking)

- **Already-deployed migrations.** If `2026_11_16_120001` already ran in production, any images it failed to move are still on the public `user_data` disk and 404 today. This job doesn't recover them; a one-off fix would need its own brief. Mention it in implementation.md under follow-ups.
- **No new migration needed.** Only existing migration files are edited, and `up()` stays the same.
