# Report: The notes list sends and caches every note's full content

date: 2026-11-21
read: false
branch: `main`, tree at `eb93bdf`
scope: Performance of `GET /notes` and `GET /notes/hidden` (`NoteService::list` / `listHidden`), plus the content-length limit on note writes. Written for the maintainer. No application code, config, migrations, or DAV wiring/schema were changed. A throwaway Pest probe was run and deleted.

## Summary

**`GET /notes` returns the full `content` (a `longText`) of every visible note, with no limit or pagination, and caches that whole array in Redis.** `NoteResource` (`app/Api/Notes/Resources/NoteResource.php:33`) always includes `content`, and `NoteService::list` (`app/Api/Notes/Services/NoteService.php:29-40`) runs it over all of the user's notes. `listHidden` (`:46-57`) does the same for hidden and system folders. The journal is a system folder, so this list gets one more note every day the user writes a journal entry.

So the payload and the cached value grow with the total amount of text the user has ever written, not with the number of notes. Every write throws the cache away: `create`, `update`, and `destroy` call `forgetUser` (`:199-205`), which drops all four note lists. Editing one note therefore means the next list request reads, serialises, stores, and sends every note's text again.

`GET /notes/{note}` already exists and returns one note with its content (`NoteController::show`). The list could leave the text out.

## Measured

Throwaway Pest probe (in-memory SQLite, array cache store, same as the test suite). Each note has about 4 KB of content, roughly a page of text. "Rebuild" means `GET /notes` after the `notes_v2` key was forgotten, which is what happens after every note edit. Three runs each:

| notes | response `data` | without `content` | rebuild (`GET /notes`) | same query, no `content` column |
|---|---|---|---|---|
| 300 | 1.30 MB | 67 KB | 106 / 68 / 71 ms | 5 ms |
| 1000 | 4.34 MB | 222 KB | 310 / 244 / 219 ms | 20 ms |

The last column only times the query (`get([...])` without `content`) and is not a full request, so it isn't a like-for-like comparison. It does show where the time goes. Content is about 95 % of the bytes. On production the same bytes also cross MariaDB → PHP → Redis (`user_data` store, 24 h TTL) → PHP → client. Redis holds a copy per list key (`notes_v2`, `notes_hidden_v2`), and every list read unserialises the whole value.

## Suggestion

1. **Leave `content` out of the list responses.** Add a list resource (or a `$withContent` switch on the existing one) that returns `id, title, category_id, tags, is_favorite, created_at, updated_at`. Optionally add a short `excerpt` for previews, built in the database or capped with `Str::limit`. Select only the columns needed in `list`/`listHidden` so MariaDB doesn't read the `longText` at all. The client loads the text with `GET /notes/{note}` when a note is opened.
   - `newest` and `recent` (5 notes each, `:59-87`) can use the same slim resource if the dashboard widgets only show titles.
   - Bump the cache keys (`notes_v3`, …) so old full-content arrays aren't served. That's the same pattern the `_v2` comment at `:16` describes.
2. **Make `show` load tags.** `NoteController::show` returns `new NoteResource($note)` without `->load('tags')`, and `tags` is `whenLoaded` (`NoteResource.php:35`). Today the client gets tags from the list. Once the list is the index and `show` provides the detail, `show` must return them too.
3. **Move the MCP search off the list.** `App\Mcp\Tools\SearchNotes::handle` loads `NoteService::list()` and runs `str_contains` over `title . content` in PHP. Without content in the list it would quietly match on titles only. Give it its own query: `where(title LIKE ? OR content LIKE ?)` with the same `whereVisible` scope, `limit(20)`, and only the columns it returns. That is also faster than unserialising the whole cached list on each search.

This is a breaking change to the `GET /notes` contract (the OpenAPI `Note` schema lists `content`), so it needs to ship together with the frontend change. See the open question below.

## Side note (security / abuse): no length limit on note content

`StoreNoteRequest` (`'content' => 'nullable|string'`) and `UpdateNoteRequest` (`'sometimes|string'`) have no `max:`. Titles have none either, and the column is `longText` (`2025_10_21_152404_change_content_of_notes.php`). The only cap is the web server/PHP body size. One account can store large notes, and with the current list design each one is copied into the Redis cache and sent with every list response. That is a cheap way for a single user to fill the cache memory that all users share. A `max:` on `content` and `title` (the size is a product decision, e.g. 1 MB / 255) bounds this whatever happens with the list change. `NoteImportService` should apply the same limit.

## Open questions

- Does the frontend need `content` in the list? Two possible reasons: client-side full-text search, or previews in the sidebar. If it does search, the API needs a server-side `GET /notes?search=` (the same query as point 3) before content can be dropped. If it shows previews, an `excerpt` field covers that.

## Not checked

- Real production note sizes. Notes holding pasted images (data URIs) would make the numbers above much worse, but I didn't verify whether the editor ever stores images inline.
- MariaDB timing. The probe ran on SQLite, so the absolute milliseconds are only indicative. The byte counts don't depend on the database.
