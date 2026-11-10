# Implementation: imdb import broken

id: group
developer: developer
date: 2026-11-10

## Summary

IMDb import broke because the unofficial `api.imdbapi.dev` host it called has shut down. The fix resolves the IMDb title ID through TMDB (`/find/{tt-id}?external_source=imdb_id`), which is already integrated. It needs no new dependency, secret, or migration. `composer test` and `composer analyse` both exit 0.

## Changes

TASK-1: `TmdbService` gains `importByImdbId(string $imdbId, string $url)`. It uses the first movie match, falls back to the first TV match, and then loads `/{type}/{id}`. The detail mapping that `importFromUrl` used is now the private `buildDetailDTO()`, which both paths share. It takes id/url/provider as parameters, so the IMDb path keeps `id` = tt-id and `provider` = `imdb`.
TASK-2: `ImdbService` now gets `TmdbService` through its constructor and delegates to `importByImdbId`. I removed the dead `GET_MOVIE_URL` constant and `getMovie()` and kept `getTitleIdFromUrl`.
TASK-3: `LibraryRestApiTest`: I replaced the dead-host fake with TMDB `/find` and `/movie` fakes, and the test asserts id/provider/link. I added cases for a TV match, no match, and a non-title URL, plus an endpoint test for `POST /api/v1/libraries/movies/import/imdb`. All of them use `Http::preventStrayRequests()`.
TASK-4: `MovieReleaseDTO::getType/getDescription/getReleaseYear` now return nullable types that match the constructor.
TASK-5: `composer test` passes (exit 0) and `composer analyse` reports no errors.

## Known issues / follow-ups

- Deviation from TASK-2: the DTO is built in `TmdbService` (via `buildDetailDTO`) rather than in `ImdbService`. PHPStan rejects array-spread named arguments, and this avoids duplicating the field mapping.
- From the open question: Quick Add still saves imported series as `movie`, because `commitMovie` checks for `'series'` while TMDB returns `'tv'`. This was not fixed because it is out of scope.
- The test run shows about 1355 warnings from a missing `/workspace/.env` in this environment. They are not related to this change.
