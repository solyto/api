# Tasks: book and music release lookups are slow on a cache miss

id: divide
analyst: analyst
date: 2026-11-17

Branch verified: `feature/divide_book-and-music-release-lookups-are-slow-on-a-cache-miss`.

## Parity notes (apply to TASK-2 … TASK-5)

The brief requires results **identical** to today. These are easy to break:

- **Order before the sort.** Today releases are appended in favourite order
  (`LibraryMusic`/`LibraryBook` query order), then `usort` by release date.
  PHP 8 `usort` is stable, so for equal release dates the order of
  appending decides the output. The parallel version has to append in the same
  input order, not in pool completion order.
- **Pool keys.** `Http::pool` results are keyed by `as()` name. Use the input
  index (cast to string) as the key and map back by index. Don't use artist or
  author names as keys. Deezer artist ids can be numeric strings that PHP turns
  into int keys.
- **Two artists, same Deezer id.** Today two different artist strings that
  resolve to the same Deezer id each get their own album fetch, and each
  produces DTOs with its own `artist` name. Round 2 may dedupe the request per
  id, but every artist must still get the releases, with its own name.
- **Failure = `null`.** `Http::pool` returns a `ConnectionException` (or other
  `Throwable`) as a value instead of throwing. The batch methods must treat
  anything that isn't a successful `Response` (500, 429, connection error) as
  `null` for that input.
  Note: today `HardcoverService::post()` does **not** catch
  `ConnectionException`, so the single `getNewReleases()` throws on a
  connection error. The brief says to keep the single methods as they are
  ("null on a failed … response" is for the batch). Leave the single method's
  behaviour unchanged. Don't add a catch there, that's out of scope.
- **Same request.** Same URLs: Deezer `SEARCH_ARTIST_URL` is built with
  `sprintf` and **no** urlencode, so the batch keeps it that way. Same
  Hardcover GraphQL query string, variables and headers (`Content-Type`,
  `Authorization: Bearer …`). Hardcover's query string should become a private
  const or helper so the single and batch paths share it.
- **Concurrency limit.** Pool in chunks of 5, one chunk after another, as a
  typed private const per service (`private const int POOL_SIZE = 5;`,
  STANDARDS 11.2). Hardcover's documented limit could not be verified: the
  official docs returned 403, and a third-party page says 60 req/min. So keep
  5, as the brief says.
- STANDARDS: 4.6 (return types), 4.8 (catch by type, i.e. check
  `instanceof Response`, no blanket `\Exception`), 10.1/10.2 (no comments
  beyond a why; `@param`/`@return` only for `array<…>` shapes), 11.1 (match
  file formatting: these two files use `!$x` without a space).

## Task breakdown

TASK-1: Set `LibraryBookService::CACHE_TTL_RELEASES` to 604800, and add a test showing that a cache miss in `releases()` stores the result for 604800 s.
  files: app/Api/Libraries/Services/LibraryBookService.php (`:24`), app/Api/Libraries/Tests/LibraryServiceTest.php (or the module test file that already covers `LibraryBookService`)
  depends: none
  risk: low, a one-constant change. The only care is the test: assert the TTL through the `UserCacheService`/Cache store (e.g. a `Cache` spy or time travel past 1 day and under 7), not by reading the constant.

TASK-2: In `HardcoverService`, move the response parsing of `getNewReleases()` (the `data.books` extraction plus the date window filter) and the GraphQL query into private helpers, and add `getNewReleasesForAuthors(array $authors): array` (author index → releases or `null`). It sends the requests through `Http::pool` in chunks of 5, with the same headers and body as `post()`.
  files: app/Api/Libraries/Services/External/HardcoverService.php, new tests in app/Api/Libraries/Tests/ (e.g. `HardcoverServiceTest.php`, or the existing `HardcoverService` describe in `LibraryApiTest.php`)
  depends: none
  risk: medium, because the single method's behaviour (including that it throws on a connection error) has to stay exactly the same while the batch returns `null`. Tests (`Http::fake`): several authors → one POST each, mapped to the right author. One author 500 / 429 / `Http::failedConnection()` → `null` for that author only. More than 5 authors → all requested (`Http::assertSentCount`).

TASK-3: In `DeezerService`, move the response parsing of `searchArtists()` and `getNewReleases()` (`data` extraction and the one-month filter) into private helpers. Add two batch methods: one for artist searches (index → search result or `null`) and one for albums by artist id (id → new releases or `null`). Each pools in chunks of 5.
  files: app/Api/Libraries/Services/External/DeezerService.php, new tests in app/Api/Libraries/Tests/ (e.g. `DeezerServiceTest.php`, or the existing `DeezerService` describe in `LibraryApiTest.php`)
  depends: none
  risk: medium, because `getNewReleases()` goes through the public `getAlbums()`, which must stay as it is. Only the parsing moves, and the batch album method must apply the same filter as `getNewReleases()`, not `getAlbums()`. Tests are the same as in TASK-2, for both rounds (search and albums).

TASK-4: Change `LibraryReleases::getBookReleases()` to collect the deduplicated authors first, make one call to `getNewReleasesForAuthors()`, then build the `BookReleaseDTO`s in author order and sort as today. Update the book mocks in the tests to the batch method, keeping the expected releases.
  files: app/Api/Libraries/Services/LibraryReleases.php (`:137`), app/Api/Libraries/Tests/LibraryServiceTest.php (`:176` ff.), app/Api/Libraries/Tests/GrabReleasesTest.php (`:101` ff.)
  depends: TASK-2
  risk: low, a small loop restructure. The DTO mapping and dedup (`explode(',')[0]`) must not change.

TASK-5: Change `LibraryReleases::getDeezerMusicReleases()` to run in two rounds: first batch-search all deduplicated artists, take `$search[0]['id']` per artist with a non-empty result, then batch-fetch albums for those ids. Build `MusicReleaseDTO`s in artist order and sort as today. Update the Deezer mocks in the tests to the batch methods, keeping the expected releases.
  files: app/Api/Libraries/Services/LibraryReleases.php (`:39`), app/Api/Libraries/Tests/LibraryServiceTest.php (`:100`–`:135`), app/Api/Libraries/Tests/GrabReleasesTest.php (`:19`–`:99`)
  depends: TASK-3
  risk: medium, because the two-round mapping (artist → id → releases) is where the order and shared-id parity notes above can slip. Don't touch `getSpotifyMusicReleases()`, `getMovieReleases()`, the jobs, or the `PulseMetrics` wrapping.

TASK-6: Run `composer test` and `composer analyse` in the worktree and fix anything they report in the changed code.
  files: whatever TASK-1…5 touched
  depends: TASK-1, TASK-2, TASK-3, TASK-4, TASK-5
  risk: low. Larastan may want `array<…>` generics on the new batch signatures, and those are allowed under STANDARDS 10.2.

## Unclear / for the owner (no blocker)

- Hardcover's rate limit is unverified (official docs 403; a third-party page
  says 60 req/min). Chunks of 5 cap the burst but not the per-minute total, and
  that total is the same as today. A user with more than about 60 favourite
  authors could already hit 429s today, and still will. Those come back as `null`.
