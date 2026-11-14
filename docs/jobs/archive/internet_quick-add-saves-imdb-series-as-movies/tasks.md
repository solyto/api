# Tasks: Quick Add saves IMDb series as movies

id: internet
analyst: analyst
date: 2026-11-14

## Task breakdown

TASK-1: In `QuickAddService::commitMovie`, map the DTO type to the category with `in_array($dto->getType(), ['tv', 'series'], true) ? 'series' : 'movie'` so TMDb's `'tv'` (and a possible `'series'`) is saved as `series`.
     files: app/Shared/Services/QuickAddService.php (line 206)
     depends: none
     risk: low — one-line condition change; `series` is already a valid category (`StoreLibraryMovieRequest`: `in:movie,series`) and is used by `LibraryMovieService::trailers()`.

TASK-2: Add two Quick Add commit tests in the `QuickAddService::commit` describe block: an IMDb series URL (e.g. `tt0944947`) with faked TMDb `/find` returning `tv_results` + `/tv/{id}` that asserts a `LibraryMovie` with `category === 'series'`, and an IMDb movie URL (faked `movie_results` + `/movie/{id}`) that asserts `category === 'movie'` as a regression guard.
     files: app/Api/Dashboard/Tests/QuickAddTest.php
     depends: TASK-1
     risk: low — test-only; reuse the fake shape from the existing `imdb imports a series from a title url` test in app/Api/Libraries/Tests/LibraryRestApiTest.php. Use `Http::preventStrayRequests()` and leave `poster_path` out of the fake (or fake the image URL too), because a cover makes `LibraryMovieService::create` call `CoverService::saveCover`, which would make a real HTTP request.

TASK-3: Run `composer test` and `composer analyse` in the worktree; both must exit 0.
     files: none
     depends: TASK-1, TASK-2
     risk: low — verification only.

## Open points (not code tasks)

- Brief note: clients of `POST /libraries/movies/import/imdb` now get `type: 'tv'` instead of `'tvSeries'`. Nothing in this repo reads `'tvSeries'` (grep finds no matches outside the brief and backlog entry). The consumer is the separate frontend repo, which this job can't inspect. A human has to confirm the frontend doesn't depend on `'tvSeries'` before the job is closed. This is not a blocker for TASK-1 to TASK-3.
- Out of scope: the `MovieReleaseImport` OpenAPI schema (`MovieReleaseResource`) documents `type` with no enum, while `LibraryMovieReleaseResource` declares `enum={"movie","tv"}`. Leave this alone unless asked.
- The source backlog entry `docs/backlog/quickadd-imdb-series-type.md` has the same text as this brief. Whether to remove it when the job closes depends on the pipeline's convention, not on this job.
