# Tasks: deactivate movie release notifications

id: elect
status: open
analyst: analyst
date: 2026-10-08

<!-- Produced by @analyst from brief.md. -->

## Findings

- The "screen release / movie release" backend job is
  `App\Api\Libraries\Jobs\GrabMovieReleases`. Its only trigger is the weekly
  schedule entry in `routes/console.php:21`:
  `Schedule::job(\App\Api\Libraries\Jobs\GrabMovieReleases::class)->weekly();`.
  A repo-wide search found no other dispatcher (no controller, command, route,
  listener or chain references it) — the remaining hit is its own test.
- The job does two things per user: (a) caches TMDB genre releases under the
  `movie_releases` `UserCacheService` key, and (b) sends `MovieReleaseNotification`
  for every release id not yet in the `movie_release_notified` set.
- The `movie_releases` cache is also read by
  `LibraryMovieService::releases()` via `remember(...)`, which the
  `GET /api/v1/libraries/movies/releases` endpoint uses. Because that read is a
  `remember`, the releases feed still works if the job stops populating the
  cache — it recomputes lazily on the first request. So disabling the job does
  not break the releases feed.
- `GrabMovieReleases` is separate from `GrabMusicReleases` and
  `GrabBookReleases`. Those are out of scope and must remain scheduled exactly
  as-is.
- Chosen interpretation of "deactivate the job, keep the rest the same for now":
  disable the job's scheduled trigger only. Comment out the schedule line with a
  short explanatory comment (easy to reverse when the bug is fixed) and leave
  the job class, `MovieReleaseNotification`, `LibraryReleases::getMovieReleases()`,
  the DTO, the per-user notification settings, and the existing tests untouched.
  This is the smallest, most obviously reversible change and keeps the existing
  `GrabReleasesTest` movie cases valid (they invoke `handle()` directly, so they
  still pass).
- Alternative considered and deliberately *not* chosen: making `handle()` a
  no-op or deleting the job. A no-op would break the existing `GrabMovieReleases`
  tests and is less obviously reversible; deleting the class throws away code
  the team may want to repair later. If product actually wants the job itself
  (not just its schedule) made inert, the reviewer/developer should flag this —
  the schedule entry is the only live trigger, so the two are equivalent at
  runtime.

## Task breakdown

TASK-1: Disable the scheduled trigger for the movie-release notification job by
     commenting out `Schedule::job(\App\Api\Libraries\Jobs\GrabMovieReleases::class)->weekly();`
     in `routes/console.php` and adding a brief comment noting it is temporarily
     deactivated pending a fix (reference this job/brief). Do not modify the job
     class, its notification, `LibraryReleases`, the DTO, the notification
     settings, or the other release-job schedule entries.
     files: routes/console.php
     depends: none
     risk: low — a one-line scheduling change; the job has no other trigger, and
            the movie releases endpoint recomputes lazily so it is unaffected.

TASK-2: Verify the change: run the focused `GrabReleasesTest` and the full Pest
     suite (`composer test`) plus `pint` on the changed file; confirm the
     scheduler no longer registers `GrabMovieReleases` while `GrabMusicReleases`
     and `GrabBookReleases` remain scheduled, and that no unrelated tests fail.
     files: none (verification only)
     depends: TASK-1
     risk: low — read-only verification; stop and report if unrelated tests fail.

## Out of scope / open questions

- Product may later want to repair rather than permanently remove the movie
  release notifications; the code is intentionally left intact so re-enabling is
  a one-line uncomment.
- Removing the now-dormant `MovieReleaseNotification`, the movie branch of
  `LibraryReleases`, the `movie_release_*` settings/migration, or the
  `GrabMovieReleases` tests is explicitly out of scope ("keep the rest the same").
- The `movie_releases` cache will no longer be proactively refreshed weekly; it
  is rebuilt on demand by `LibraryMovieService::releases()`, so no follow-up is
  needed unless someone relies on the cache being warm.
