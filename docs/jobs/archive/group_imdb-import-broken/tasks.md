# Tasks: imdb import broken

id: group
analyst: analyst
date: 2026-11-10

## Root cause

`App\Api\Libraries\Services\External\ImdbService` fetches title data from
`https://api.imdbapi.dev/titles/{tt-id}`. That unofficial service has shut down:
the host no longer resolves (verified 2026-11-10: `getaddrinfo ENOTFOUND
api.imdbapi.dev`, also `imdbapi.dev`). Other projects report it went offline in
July 2026, with no successor host. `Http::get` throws `ConnectionException`, and
`getMovie()` catches it and returns `null`. So every IMDb import returns
`404 Movie not found.` (`POST /api/v1/libraries/movies/import/imdb`), and
Quick Add of an imdb.com link returns `null` (`QuickAddService::commitMovie`).
The URL parsing (`#/title/(tt\d+)#`) is fine. The existing test still passes
only because it fakes the dead host.

## Proposed fix (assumption — see Open questions)

Resolve the IMDb title ID through TMDB, which is already integrated
(`TmdbService`, token via `config('services.tmdb.access_token')`). TMDB's
`GET /3/find/{imdb_id}?external_source=imdb_id` returns `movie_results` /
`tv_results`. Then fetch full details with the existing `/movie/{id}` or
`/tv/{id}` calls. This needs no new dependency, secret, or migration.

The IMDb DTO must keep `id` = the `tt…` IMDb ID, `url` = the original IMDb URL,
and `provider` = `imdb`. `MovieReleaseDTO::getLink()` builds
`https://www.imdb.com/title/{id}` from `id`, and Quick Add stores that as the
movie `link`.

## Task breakdown

TASK-1: Add an IMDb-ID lookup to `TmdbService`: call `/find/{imdbId}?external_source=imdb_id`, take the first movie result (otherwise the first TV result), load its details through the existing `/{type}/{id}` path, and return the same field mapping `importFromUrl` uses (title/name, overview, poster, year, runtime, genres) without duplicating it.
     files: app/Api/Libraries/Services/External/TmdbService.php
     depends: none
     risk: medium — touches the shared TMDB detail mapping used by the existing TMDB import; extract it carefully so `importFromUrl` behaves exactly as before.

TASK-2: Rewrite `ImdbService::importFromUrl` to use the TASK-1 lookup instead of `api.imdbapi.dev`. Remove the dead `GET_MOVIE_URL` constant and `getMovie()`, keep `getTitleIdFromUrl`, and build the DTO with `id` = tt-id, `url` = the input URL, `provider` = `imdb`. Return `null` when there is no ID or no TMDB match.
     files: app/Api/Libraries/Services/External/ImdbService.php
     depends: TASK-1
     risk: low — small, self-contained service; inject `TmdbService` via the constructor (the container resolves it as it does in `LibraryMovieService`).

TASK-3: Update the Pest tests. Replace the `api.imdbapi.dev` fake in "imdb imports a movie from a title url" with TMDB `/find` and `/movie/{id}` fakes. Assert title/year/runtime plus `getId()` = tt-id and `getLink()` = the imdb.com URL. Add cases for a TV match (`tv_results`), no match (empty results → `null`), and a non-title IMDb URL (→ `null`). Add one endpoint test for `POST /api/v1/libraries/movies/import/imdb` returning 200 with the envelope.
     files: app/Api/Libraries/Tests/LibraryRestApiTest.php (optionally app/Api/Dashboard/Tests/QuickAddTest.php for a commit test)
     depends: TASK-2
     risk: low — test-only; follow docs/TESTING.md and fake every HTTP call (no real network).

TASK-4: Make the `MovieReleaseDTO` getters match their nullable constructor params (`getType(): ?string`, `getDescription(): ?string`, `getReleaseYear(): ?int`). Today a title with no overview or year throws a `TypeError` in `MovieReleaseResource`, and the OpenAPI schema already declares these fields nullable.
     files: app/Api/Libraries/DTOs/MovieReleaseDTO.php
     depends: none
     risk: low — widening return types. Check callers with `composer analyse` (e.g. the `usort` in `TmdbService::getReleasesForGenres`, `QuickAddService::commitMovie`).

TASK-5: Run `composer test` and `composer analyse` in the worktree. Both must exit 0.
     files: none
     depends: TASK-1..TASK-4
     risk: low — verification only.

## Open questions

1. **Replacement provider.** I assumed TMDB `/find` because it is already integrated and needs nothing new. If the owner prefers something else (OMDb would need a new API key/secret; scraping imdb.com is fragile), TASK-1/2 change. If no replacement is wanted, the alternative is to drop IMDb import and the Quick Add detection, which is a larger product decision.
2. **Quick Add category (not in scope, flagged only).** `QuickAddService::commitMovie` sets `category` to `series` only when `getType() === 'series'`. TMDB returns `'tv'` (and imdbapi.dev used other values), so imported series are saved as `movie`. This is a related bug but not part of "import broken"; fix it only if the owner wants it in this job.
3. Branch check: no shell was available to run `git branch --show-current`. The worktree gitdir (`.git/worktrees/group_imdb-import-broken`) matches the brief's `feature/group_imdb-import-broken`, but this is not verified.
