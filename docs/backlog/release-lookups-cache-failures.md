# Backlog: failed release lookups are cached as "no releases"

created: 2026-11-18

## What

When a Hardcover or Deezer lookup for one author/artist fails, the user's
release list is cached without that author, and stays that way until the
cache expires (books: 7 days since this release, music: until the next run
of the daily job).

## Why

Found in the release review for the version after 1.1.3 (not verified
against the live APIs).

- `LibraryBookService::releases` / `LibraryMusicService::releases` cache the
  whole result per user (`CACHE_KEY_RELEASES`, `CACHE_TTL_RELEASES`).
- The external services turn every failure into `null`: 429, 5xx, timeouts
  and `ConnectionException` (`HardcoverService.php` around 67/102/157/221,
  `DeezerService.php` around 28–183). `LibraryReleases` then skips that
  author silently.
- The lookups now run in parallel, which makes hitting the provider's rate
  limit more likely for users with many authors. Deezer reports quota errors
  with HTTP 200 and an `error` body.
- The book cache TTL went from 1 day to 7 days, so a bad result lives longer.

Effect: some authors' releases are missing for up to a week, with no error.

## Suggestion

- Let the services tell "no releases" apart from "lookup failed" (e.g. throw
  or return a failure marker instead of `null` on transport/HTTP errors and on
  Deezer `error` bodies).
- If any lookup in a run failed, return the partial result but don't cache it
  (or cache it briefly, e.g. 10 minutes).
- Tests: a failing lookup is not cached; a Deezer 200 with an `error` body
  counts as failed.

Check: `grep -n "return null" app/Api/Libraries/Services/External/{Hardcover,Deezer}Service.php`
