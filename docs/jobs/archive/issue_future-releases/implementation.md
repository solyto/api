# Implementation: future releases

id: issue
status: open
developer:
date: 2026-08-25

<!-- Produced by @developer after implementation. -->

## Summary

Fixed the "same release re-notified every day" bug. All three release-notification
jobs (`GrabMusicReleases`, `GrabBookReleases`, `GrabMovieReleases`) previously
compared `release->getReleaseDate()` against a moving `lastNotification`
timestamp window (`now()` of the previous run). A future-dated release is always
greater than any past window, so it was re-notified on every run.

Notifications are now exactly-once per release: each job keeps a per-user set of
already-notified release IDs (keyed on the stable `getId()` of each DTO) and only
notifies releases whose ID is not yet in the set, adding them afterwards. The
release date is disregarded entirely. The sets are stored in the new long-term
`longterm` cache store (the `persistent_cache` Redis DB introduced by the tunnel
job) with no TTL, so the dedup state survives deploys that flush the ephemeral
cache DB. The movie job no longer calls the non-existent `getReleaseDate()`
(which crashed), fixing that path as well.

## Changes

TASK-1: `app/Api/Libraries/Jobs/GrabMusicReleases.php` — replaced the
`music_release_last_notification` timestamp logic with a `music_release_notified`
ID-set in the `longterm` cache store. Notify only IDs not in the set, then store
the updated set (TTL 0 = keep forever).

TASK-2: `app/Api/Libraries/Jobs/GrabBookReleases.php` — identical exactly-once
change for books (`book_release_notified`, keyed on `BookReleaseDTO::getId()`).

TASK-3: `app/Api/Libraries/Jobs/GrabMovieReleases.php` — identical change for
movies (`movie_release_notified`, keyed on `MovieReleaseDTO::getId()`). Removed
the `$release->getReleaseDate()` call (not present on `MovieReleaseDTO`) which
crashed the job; the notification's `releaseDate` field is passed as `''` since
movie DTOs carry no release date.

TASK-4: Tests + supporting change:
- `app/Api/Libraries/Tests/GrabReleasesTest.php` (new) — Pest coverage for all
  three jobs: a new release is notified and added to the long-term set; a
  release already in the set is not re-notified; the movie path no longer
  crashes and dedups on the TMDB string id.
- `app/Shared/Services/UserCacheService.php` — added an optional `$storeName`
  constructor parameter (default `user_data`, fully backward-compatible) so the
  jobs can target the `longterm` store; `store()` now treats a non-positive TTL
  as "keep forever" (Laravel's `Repository::put` would otherwise delete the
  key). All pre-existing callers pass positive TTLs, so behavior is unchanged
  for them.

Also ran `laravel/pint` on the touched files (style normalization in the three
jobs and `UserCacheService`).

## Known issues / follow-ups

- The notified-set is "forever" (no TTL) by design; there is currently no way to
  reset it if a user wants to re-notify old releases. Acceptable per the brief
  (exactly-once "New Releases" only). A follow-up could add a per-user reset
  endpoint or a `forgetByPrefix`-based cleanup.
- Movie notifications now carry an empty `releaseDate` (movies have no release
  date in the DTO); the notification copy will render an empty date. If a date
  is desired later, `MovieReleaseDTO`/TMDB mapping would need a release-date
  source.
- Pre-existing environment test failures (unrelated to this change, present
  before): `LibraryServiceTest` AiService tests (OpenAI API key unavailable via
  Docker secrets) and `AuthTest` password-reset tests (app hash key null —
  empty `.env.testing`). Full suite: 639 passed / 7 failed (all 7 pre-existing).
- `composer install` was run in this workspace to restore the missing `vendor/`
  directory (locked dependencies only; `composer.json`/`composer.lock`
  untouched). Local `storage/framework/*` skeleton dirs and an empty `.env`
  (both gitignored) were created to satisfy the test bootstrap.