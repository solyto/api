# Verdict: Quick Add saves IMDb series as movies

id: internet
reviewer: reviewer
date: 2026-11-14

## Review

TASK-1: PASS
notes: `app/Shared/Services/QuickAddService.php:206` now uses `in_array($dto->getType(), ['tv', 'series'], true) ? 'series' : 'movie'`. This matches `TmdbService` (`app/Api/Libraries/Services/External/TmdbService.php:220,246,301`), which sets `type: 'tv'` for series. No other source changes.

TASK-2: PASS
notes: `app/Api/Dashboard/Tests/QuickAddTest.php` adds two tests to the `QuickAddService::commit` block. Both use `Http::preventStrayRequests()` and fake `/find` plus `/tv/{id}` or `/movie/{id}`, with no `poster_path`, so no cover download happens. The series test asserts `category === 'series'` and the movie test asserts `category === 'movie'`. Both tests run and pass.

TASK-3: PASS
notes: I re-ran both checks in the worktree. `composer test` exited 0 (1529 tests, 5450 assertions). Every warning is the phpdotenv `file_get_contents(/workspace/.env)` warning caused by the missing `.env`; no test failed. `composer analyse` exited 0 with `[OK] No errors`.

Scope: the diff against `main` covers only the two files above plus job docs, which matches `implementation.md`. `git status` shows `.env.testing` as modified, but git can't hash it ("unsupported file type"). It's a mount artifact of the environment, not part of the branch.

## Security

none

## Overall

APPROVED

No blockers. Outside this job: a human must confirm the frontend no longer depends on `type: 'tvSeries'` from `POST /libraries/movies/import/imdb` before closing, as the brief notes.
