# Report: Note list sends and caches the full text of every note

date: 2026-11-15
read: false
branch: `main`, tree at `287f895`
scope: Performance of `GET /notes` (and `GET /notes/hidden`, `GET /notes/newest`) in `NoteService`. Written for the maintainer. No application code, config or migrations were changed. A throwaway probe test was run and then deleted.

## Summary

**`GET /notes` returns the full `content` of every visible note, and the whole collection of Eloquent models is cached in Redis for 24 hours.** `NoteService::list` (`app/Api/Notes/Services/NoteService.php:25-36`) loads every note with no column list. `NoteResource` (`app/Api/Notes/Resources/NoteResource.php`) always includes `content`. The column is `longText` (`2025_10_21_152404_change_content_of_notes.php:15`), and neither `StoreNoteRequest` nor `UpdateNoteRequest` caps its length.

Every create, update or delete of any note clears all three list caches (`forgetUser`, `NoteService.php:178-183`). So the next list call rebuilds the full payload from the database and writes it back to Redis. If the frontend autosaves while someone types, this happens once per save.

The cost grows with the **total text** a user has written, not with what a list screen shows. It never goes down on its own.

## Measured

Probe: 300 notes with ~4.2 KB of content each, one `PUT` to a note, then `GET /notes` (SQLite in-memory, array cache store):

| | bytes |
|---|---|
| `GET /notes` response | **1 319 702** |
| same response without `content` | 59 902 (22× smaller) |
| `serialize()` of the cached collection | **3 069 084** |
| queries for the list after the `PUT` | 3 (user, hidden categories, notes + tags) |

The query count is fine. The cost is in bytes:

- **Cache ≈ 2.3× the response.** The cached value is the Eloquent collection, not the array. Each model serializes `attributes` **and** `original`, so every note's content is stored twice, plus the class metadata. In production this is the `user_data` Redis store (`config/cache.php:48-52`), held for 24 h per user (`CACHE_TTL = 86400`).
- **Every rebuild moves the whole thing three times:** MariaDB → PHP (all `longText` rows), PHP → Redis (3 MB `SET`), PHP → client (1.3 MB JSON). On a cache hit it is still a 3 MB Redis `GET` plus unserialize, then 1.3 MB JSON.
- Whether nginx gzips the API response is set in the deployment repo and was not checked. Gzip would shrink the transfer but not the Redis, PHP memory or serialization cost.

`listHidden` (`:42-53`) behaves the same for notes in hidden or system categories, which includes the journal. A daily journal grows by one note a day. `newest` (`:55-66`) is capped at 5 notes but also sends and caches their full content.

## Suggestion

Ship this in steps so that installed apps keep working (see `frontend-backwards-compatibility-2026-10-14.md`: removing a field from a `v1` response breaks older builds).

1. **Cache arrays, not models (API only, no contract change).** Cache `NoteResource::collection(...)->resolve()` (or the query result's `toArray()`) instead of the model collection. Same JSON, about half the Redis size and much cheaper unserialize. The other `UserCacheService::remember` callers that cache models (todos, time-tracking, feeds) could get the same treatment later. That is out of scope here.
2. **Additive summary list.** Add an opt-in summary shape, e.g. `GET /notes?summary=1`, that selects `id, title, category_id, is_favorite, created_at, updated_at` plus tags, and optionally an `excerpt` (first ~200 characters, computed in SQL with `LEFT(content, 200)` so the full text is never loaded). It gets its own cache key. Default `GET /notes` stays unchanged.
3. **Frontend switch.** The list and sidebar use the summary. The full text is loaded through `GET /notes/{note}` only when a note is opened (that route already exists, `NoteController::show`). Client-side full-text search, if it reads the cached list, would need a server-side search or would have to keep using the full list. **This needs checking in the frontend repo before deciding.**
4. **Contract later.** Once old builds are gone, `GET /notes` can default to the summary.

Step 1 alone should cut the Redis footprint roughly in half with no client impact. Steps 2–3 shrink the list payload by about 20× for users who write long notes.

### Related (security, smaller)

`content` has no `max:` rule in `StoreNoteRequest` / `UpdateNoteRequest`. A single request is limited only by `post_max_size` and MariaDB `max_allowed_packet`, and each oversized note then lands in all three caches. A generous limit (e.g. `max:1000000` characters) would bound the per-note cost. Check existing user data before choosing a number, so that a limit does not block saving notes that are already longer.

## Open questions

- Does the frontend need `content` in the list (previews, local search), or only on the detail view?
- How often does the frontend send `PUT /notes/{id}` while editing (autosave debounce)? This decides how often the 3 MB rebuild runs.
- Does the production nginx gzip `application/json`?
