# Implementation: book and music release lookups are slow on a cache miss

id: divide
developer: developer
date: 2026-11-17

## Summary

Book releases cached on a request miss now live 7 days, the same as the weekly job.
Release lookups now run concurrently through `Http::pool` with at most 5
requests in flight per service: one batch for all Hardcover authors, and two
batches for Deezer (artist searches, then albums). The parsing of the single
methods moved into private helpers that the batch methods share. Results
(dedup, DTOs, order, sort) are unchanged.

## Changes

TASK-1: `LibraryBookService::CACHE_TTL_RELEASES` 86400 → 604800. Test in
`LibraryServiceTest.php` ("LibraryBookService"): the entry stored by a miss in
`releases()` is still there at 604799 s and gone at 604801 s. The test fails if
the TTL is set back to 86400.

TASK-2: `HardcoverService`: the GraphQL query is now `NEW_RELEASES_QUERY`, the
headers are a private `headers()` (shared with `post()`), and parsing plus the
date window is `parseNewReleases()`. New `getNewReleasesForAuthors(array): array`
(input key → releases or `null`). The single `getNewReleases()` behaves as
before, and still throws on a connection error. New `HardcoverServiceTest.php`:
mapping and request shape, parity with the single method, 500/429/connection
error → `null` for that author only, 12 authors → 12 requests.

TASK-3: `DeezerService`: `parseData()` (successful → `data`) is shared by
`searchArtists()`, `getAlbums()` and the pool helper. `filterNewReleases()` is
shared by `getNewReleases()` and the batch. New `searchManyArtists(array)`
(input key → search result or `null`) and `getNewReleasesForArtists(array)`
(artist id → new releases or `null`, one request per distinct id). New
`DeezerServiceTest.php` has the same cases for both rounds, plus a duplicate id
requested once.

TASK-4: `LibraryReleases::getBookReleases()` collects the deduplicated authors,
makes one batch call, and builds the DTOs in author order before the same
`usort`. The mocks in `LibraryServiceTest`/`GrabReleasesTest` now use the batch
method. New test: comma split, dedup, a `null` author skipped, sort order.

TASK-5: `LibraryReleases::getDeezerMusicReleases()` does dedup, then round 1
(search), then round 2 (albums per found id). DTOs are built in artist order,
and each artist keeps its own name when two artists share a Deezer id. Mocks
updated. New test: shared id, search miss, stable sort.

TASK-6: `composer test` (exit 0) and `composer analyse` (no errors) pass.
Review fix: the two new arrow functions in `DeezerService` now use `fn(` to
match the file (STANDARDS 11.1).

## Known issues / follow-ups

- Concurrency limit: I didn't pool in fixed chunks of 5. I used `Http::pool`'s
  built-in `$concurrency` argument (Laravel 12.70) set to `POOL_SIZE = 5`. It
  is a rolling window: never more than 5 requests in flight, but a slow request
  doesn't hold up the next 4. The cap from the brief still holds. The tests
  don't verify the in-flight limit itself (`Http::fake` resolves synchronously).
- Hardcover's rate limit is still unverified (see tasks.md). The per-minute
  total is unchanged from before.
- The worktree had no `vendor/` and no `storage/framework/`. I ran
  `composer install` (lock file, no new packages) and created the gitignored
  storage dirs so the suite could run. `.env.testing` is mounted as
  `/dev/null` in this session, so the test runs warn about the missing `.env`.
