# Backlog: book and music release lookups are slow on a cache miss

created: 2026-11-17
updated: 2026-11-17 (scope decided by the owner, ready for implementation)

## Job (decided, do exactly this)

`GET /libraries/books/releases` and `GET /libraries/music/releases` serve from
the cache. On a miss, the request runs the lookup itself: one external call per
favourite author (Hardcover) or two per artist (Deezer: search, then albums),
**one after another**. Pulse (1.1.3, 7 days): 3 requests, up to 19.5 s.

Two changes:

### 1. Book releases: same cache lifetime on a miss as from the job

`LibraryBookService::CACHE_TTL_RELEASES` (`LibraryBookService.php:24`) is
86400 (1 day), while the weekly `GrabBookReleases` job stores the same key for
604800 (7 days, `GrabBookReleases.php:24`). After one miss, a user's entry
expires daily and the next visit fetches live again. Set it to 604800, like the
job. Music stays as it is (daily job, 1 day in both places).

### 2. Run the external calls in parallel

In `LibraryReleases` (`app/Api/Libraries/Services/LibraryReleases.php`):
- `getBookReleases()` (`:137`): send the Hardcover request for all distinct
  authors concurrently instead of one by one.
- `getDeezerMusicReleases()` (`:39`): first all artist searches concurrently,
  then all album requests for the found artist ids concurrently (two rounds).

How:
- Add batch methods to the services, using Laravel's `Http::pool` (see
  `FeedReader::pool()` at `app/Api/Feeds/Services/FeedReader.php:196` for a
  pool in this codebase), e.g. `HardcoverService::getNewReleasesForAuthors(array
  $authors): array` (author → releases or null) and in `DeezerService` one
  batch method per round. Each returns, per input, exactly what the existing
  single method returns today for that input (same request, same parsing,
  same filtering by date, `null` on a failed or unsuccessful response).
- Move the response parsing of the existing single methods
  (`HardcoverService::getNewReleases()` `:86`, `DeezerService::searchArtists()`
  `:41`, `DeezerService::getNewReleases()` `:86`) into private helpers that both
  the single and the batch methods use, so the parsing exists once. Keep the
  single methods: other code may call them.
- **Limit concurrency:** send at most 5 requests at a time per service (pool in
  chunks of 5), so a user with many favourites doesn't burst the external APIs
  (Deezer allows about 50 requests per 5 s; check Hardcover's documented limit
  and keep 5 if unsure). A 429 or any failed response counts as "no result" for
  that author/artist, as it does today.
- **Results must be identical** to today: same deduplication of
  authors/artists (first part before a comma), same DTOs, same final sort by
  release date.
- The scheduled jobs (`GrabBookReleases`, `GrabMusicReleases`) call the same
  `LibraryReleases` methods and benefit automatically; no job changes.

## Out of scope (do not do)

- Answering without a live fetch on a miss (empty list or last result plus a
  background job). Decided against: a live fetch is better than no fetch.
- Changing the HTTP timeouts of the Hardcover or Deezer calls. Decided against
  for now.
- The dormant Spotify path (`getSpotifyMusicReleases()`), movie releases, and
  the `PulseMetrics::timedReleaseLookup` wrapping (stays as it is).
- Any change to what the endpoints return.

## Tests

- Existing tests keep their assertions: `LibraryServiceTest.php` ("LibraryReleases"
  section, `:100` ff.) and `GrabReleasesTest.php` mock `DeezerService` /
  `HardcoverService`. Update the mocks to the new batch methods where
  `LibraryReleases` now calls those; the expected releases stay the same.
- New tests for the batch methods with `Http::fake`:
  - several authors/artists: one request each (Deezer: search and albums),
    results mapped to the right input;
  - one failing response (500, 429, connection error) yields `null` for that
    input only, the others still return their releases;
  - more inputs than the chunk size are all requested.
- Book TTL: a cache miss in the request path stores the result for 604800 s.

## Checks

- `composer test` and `composer analyse` pass (see `docs/AGENTS.md`).
- STANDARDS.md applies to changed code (`docs/policies/coding-standards.md`).
- No new packages.

## Background (for context, no action)

The Pulse "Release Lookups" card (`app/Shared/Pulse`) shows lookups per library
and source (`request` = the user waited, `job` = scheduled). After this change,
the `request` rows should show clearly lower averages.
