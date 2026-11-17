# Backlog: book and music releases are fetched live on a cache miss

created: 2026-11-17

## What

`GET /libraries/books/releases` and `GET /libraries/music/releases` serve
from the cache, but on a miss the request does the full external lookup
itself, one call per favourite author or artist, one after another. Make a
miss cheap for the user.

## Findings

Laravel Pulse (production, 1.1.3, past 7 days, 1000 ms threshold):

| Route | Count | Slowest |
|---|---|---|
| `GET /api/v1/libraries/books/releases` (`LibraryBookController@releases`) | 2 | 19,502 ms |
| `GET /api/v1/libraries/music/releases` (`LibraryMusicController@releases`) | 1 | 11,396 ms |

Code:

- `LibraryBookService::releases()` / `LibraryMusicService::releases()` use
  `cache->remember()` with `LibraryReleases::getBookReleases()` /
  `getMusicReleases()` as the fallback.
- `getBookReleases()` (`app/Api/Libraries/Services/LibraryReleases.php:137`)
  takes all books rated ≥ 4, dedupes authors, and calls
  `HardcoverService::getNewReleases()` per author, sequentially. Music does
  the same per artist via Deezer (`searchArtists()`).
- The Hardcover request (`HardcoverService.php:183`) sets no explicit
  timeout, so Laravel's HTTP default applies.

Why misses happen:

- **Mismatched TTLs for books.** The weekly job `GrabBookReleases` stores
  the result for 7 days (`GrabBookReleases.php:23`, 604800). A miss in the
  request path stores it for 1 day (`LibraryBookService.php:23`, 86400). After
  one miss, the user's entry expires daily, and every next visit after
  expiry fetches live until the weekly job runs again.
- **Music** is refreshed daily at 05:00 (`GrabMusicReleases`) with a 1-day
  TTL. The entry may expire shortly before the job reaches that user, leaving
  a small window for a miss.
- **Deploys:** the job comments say the ephemeral cache may be flushed on
  deployment. Then every user's first visit fetches live.
- **New users / new favourites** have no entry until the next job run.

## Options

1. **Align the book TTL:** the request path stores for 7 days like the job.
   One-line change; fewer repeated misses, but the first miss stays slow.
2. **Don't fetch live in the request:** on a miss, return an empty list (or
   the last known result from a longer-lived store) and dispatch a job that
   fills the cache. The page shows releases on the next visit. Needs a way
   to avoid dispatching the same job repeatedly (e.g. `ShouldBeUnique` per
   user).
3. **Fetch in parallel:** query all authors/artists concurrently
   (`Http::pool`). Cuts the miss from N × call time to about one call, but
   still waits on external APIs in the request.
4. **Explicit timeout** on the Hardcover (and Deezer) requests, so one slow
   call can't hold the request for the default timeout.

Options combine (e.g. 1 + 4 quickly, 2 or 3 later).

## Notes

- Rare today (3 slow requests in 7 days), but 10–20 s of loading for the
  users it hits.
- Movie releases: `GrabMovieReleases` is commented out in
  `routes/console.php`, so this entry doesn't cover movies.
