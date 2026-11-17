# Backlog: library edits reload the whole list (API side)

created: 2026-11-17

## What

Frontend report `2026-11-17-library-reload-after-every-click` (solyto/app):
every edit in the 8 library stores (rating, star, category drag, form) ends
with a full `load()`. That is the list, then the genres/categories, one after
the other. A star click costs PUT + 2 serial GETs, about 3 round trips before
the UI reacts. This entry records the API facts, the options and the decision
from 2026-11-17. The frontend has its own entry.

**Decision 2026-11-17: not doing it now.** The full reload is deliberate:
other tabs/devices and sort order stay correct because the list always comes
from the server. Every option that keeps that guarantee gains little.

## API facts (checked 2026-11-17)

- **Validation before any write.** Every library `Update…Request` validates
  all fields first. A 422 returns `errors = {field: [messages]}`
  (`bootstrap/app.php`) and nothing is stored.
- **Stored as sent, nothing clamped.**
  - `rating`: `nullable|integer|min:1|max:5` for Book/Movie/Music/Game/Recipe.
    Plants and Quotes have no rating.
  - `is_favorite`: `boolean`.
  - `category_id`: `exists` within the user's own link categories, so a
    foreign category gives a 422.
  - No observers or model events in `app/Api/Libraries`.
- **The PUT already returns the full entity.** Same Resource as the list.
  Relations are reloaded via `load()` after the sync. Scalar attributes are
  not re-read (no `refresh()`): they are the in-memory values as sent.
- **Not atomic.** No transaction. Order in `Library*Service::update`:
  `saveCover` (synchronous download of the cover URL) → `$model->update` →
  genres sync → tags sync. A failure after `update()` saves the fields but
  returns a 500. Rare, because IDs are validated beforehand.
- **No side effects on other entries.** No position fields, no stored
  counters. `GenerateCoverPreview` only writes a preview file. Plant deletes
  its old cover file. The server list cache is forgotten on every update.
- **Lists sort** by author/title/artist/name/created_at. None sort by rating,
  favorite or category.
- **No boolean casts on the library flags:** `is_favorite` (Link),
  `wishlist` (Book/Movie/Music/Game/Plant), `completed` (Game),
  `winter_hardy` (Plant, nullable). The list (MariaDB) returns `1`/`0`, while
  the PUT response returns whatever the client sent. OpenAPI documents
  `is_favorite` as boolean, and `Note` already casts it.

## Options

**A. Keep the full reload, make it cheaper** (frontend only)

- Load list + genres/categories in parallel instead of serially: ~3 → ~2
  round trips per click, one fewer on page load.
- Fix the store `update()`/`delete()` return value. It is always true today,
  so errors are invisible. This part is worth doing on its own.
- Keeps every guarantee. Small gain.

**B. Optimistic UI + full reload** (frontend only)

- Flip the star/rating immediately, then still do the full reload, which
  overwrites local state with server state.
- Instant feedback, multi-tab and sorting stay correct. On error, the value
  flips back and an error shows.

**C. Replace the entry with the PUT response for rating/star/category drag
only; forms keep the reload**

- 1 round trip per click. These fields are not sort keys, so the order
  can't break.
- Loses the "self-healing" of each click: changes from another tab or device
  appear only on the next page visit or reload.
- API prerequisites: boolean casts on the flags above (no cache bump needed,
  the caches hold Eloquent models and the casts apply at serialization), and
  ideally a transaction + `refresh()` (see below).

**Rejected**

- Replace the entry after form PUTs too: sort order breaks when
  author/title change, re-sorting locally duplicates the server sort
  (collation, nulls, tiebreaker), and there is more logic in 8 stores.
- Skip the genres/categories reload on entry edits: genres/categories
  changed in another tab would no longer refresh, and it's no faster than
  loading in parallel.

## Independent API hardening (any option, or none)

- Wrap `$model->update` + genre/tag syncs in `DB::transaction` in the 8
  `Library*Service::update` methods, so a failed sync no longer leaves a
  partial write. Keep `saveCover` **outside** the transaction (HTTP
  download, otherwise row locks are held during it). If the transaction
  rolls back, the downloaded cover file is orphaned. That already happens
  today.
- `refresh()` before `load()`, so the response equals the DB state (+1
  query per PUT).
- Boolean casts on the library flags so list and PUT return the same types.
  Known clients (solyto/app) only use truthiness and already type these as
  boolean. A third-party script comparing `=== 1` would break.
- Tests: list and PUT return the same shape/types, and a failing sync rolls
  back the whole update.

## Side note

`LibraryLinkService::update`/`store` save link covers with
`LibraryTypeEnum::BOOK`, so they go to the book cover directory. Unrelated to
this entry, but found while checking it.
