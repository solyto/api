# Verdict: imdb import broken

id: group
reviewer: reviewer
date: 2026-11-10

## Review

Branch verified: `feature/group_imdb-import-broken`. Diff reviewed: `git diff main...HEAD`.
Full suite run in the worktree: `composer test` exit 0 (all assertions pass). The roughly 1355 warnings come from the missing `/workspace/.env` in this environment and are not related to this job. `composer analyse` exit 0, `[OK] No errors`.

TASK-1: PASS
notes: `TmdbService::importByImdbId` calls `/find/{tt}` with `external_source=imdb_id`. It prefers `movie_results[0]` and falls back to `tv_results[0]`, then loads `/{type}/{id}`. The shared mapping now lives in the private `buildDetailDTO`. `importFromUrl` behaves the same as before. The old `$result['id']` access inside the try would also have produced null on a missing id. The new `!isset($result['id'])` guard makes that explicit. The movie and TV mappings (title/name, release_date/first_air_date, runtime/episode_run_time[0]) match the old code.

TASK-2: PASS
notes: `ImdbService` injects `TmdbService`. The dead `GET_MOVIE_URL` and `getMovie()` are gone, and `getTitleIdFromUrl` is kept. The `tt\d+` regex keeps the path segment safe. The DTO is built in `TmdbService` instead of `ImdbService`, which `implementation.md` documents as a deviation. It still has `id` = tt-id, `url` = input, `provider` = `imdb`, so the intent is met. The only consumer is `LibraryMovieService`, which resolves through the container, so the constructor change is safe.

TASK-3: PASS
notes: `LibraryRestApiTest` covers a movie match (id/provider/link asserted), a TV match (also checks the nullable description), no match → null, and a non-title URL → null with `assertNothingSent`. It also has the endpoint test for `POST /api/v1/libraries/movies/import/imdb` with the envelope. Every test uses `Http::preventStrayRequests()`.

TASK-4: PASS
notes: `getType`, `getDescription` and `getReleaseYear` are now nullable, matching the constructor. Analyse is clean.

TASK-5: PASS
notes: The reviewer confirmed both commands exit 0, as stated above.

Scope: nothing changed outside `tasks.md`. The Quick Add `series`/`tv` category issue is correctly left as a flagged follow-up.

## Security

none — no new dependency, secret, or migration. The token still comes from `config('services.tmdb.access_token')`.

## Overall

APPROVED
