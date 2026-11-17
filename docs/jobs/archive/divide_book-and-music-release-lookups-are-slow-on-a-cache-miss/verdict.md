# Verdict: book and music release lookups are slow on a cache miss

id: divide
reviewer: reviewer
date: 2026-11-17

## Review

Re-review after the fix commit `cd6c018`. Reviewed the full `git diff main...HEAD`
on `feature/divide_book-and-music-release-lookups-are-slow-on-a-cache-miss`.
The full `composer test` run exits 0 (6344 assertions). Every test shows the same
missing `.env` warning, because `.env.testing` is mounted as `/dev/null` in this
session. `composer analyse` reports `[OK] No errors`.

TASK-1: PASS
notes: `LibraryBookService.php:24` is now 604800. The test uses time travel (still
cached at 604799 s, gone at 604801 s), so it checks the stored TTL.

TASK-2: PASS
notes: `NEW_RELEASES_QUERY`, `headers()` and `parseNewReleases()` are shared by the
single and the batch paths. `getNewReleases()` behaves as before, including the
throw on a connection error. The batch maps anything that isn't a successful
`Response` to `null`. Concurrency is capped at 5 in flight through the `Http::pool`
concurrency argument. The brief's cap ("at most 5 requests at a time") holds, and
implementation.md documents the choice.

TASK-3: PASS
notes: The earlier blocker is fixed: `DeezerService.php:60` and `:109` now use
`fn(` (STANDARDS 11.1). `parseData()` and `filterNewReleases()` are shared. URLs are
unchanged (no urlencode). Single-array `array_map` keeps the keys, so the results
map back to the right inputs. A duplicate id is requested once. Failures map to `null`.

TASK-4: PASS
notes: The dedup is unchanged. There is one batch call, DTOs are built in author
order, and the same `usort` runs. Mocks were updated and expected releases are unchanged.

TASK-5: PASS
notes: The two rounds are keyed by input index. When two artists share a Deezer id,
each still gets the releases under its own name (tested). DTOs are built in artist
order before the sort. Spotify, movies, jobs and `PulseMetrics` are untouched.

TASK-6: PASS
notes: `composer test` exits 0 and `composer analyse` reports no errors.

Scope: no changes outside the task list. No new packages.

## Overall

APPROVED

Nothing must change before merge.
