# Verdict: future releases

id: issue
status: open
reviewer:
date: 2026-08-25

<!-- Produced by @reviewer and/or @security after implementation. -->

## Review

TASK-1: PASS
notes: `app/Api/Libraries/Jobs/GrabMusicReleases.php` — timestamp window replaced by a
per-user `music_release_notified` ID-set in the `longterm` cache store. Only IDs not in
the set are notified, then the set is stored with TTL 0 (→ `forever` via
`UserCacheService::store`). Date comparison removed; no remaining references to
`music_release_last_notification` anywhere in the codebase (grep verified).
Note: the TASK-1 commit itself lands before the `store()` TTL≤0→`forever` handling
(added in TASK-4's commit), so the intermediate state would have deleted the key on
Laravel's `Repository::put` (seconds ≤ 0 ⇒ `forget`). Final state is correct; not a blocker.

TASK-2: PASS
notes: `app/Api/Libraries/Jobs/GrabBookReleases.php` — identical exactly-once change,
keyed on `BookReleaseDTO::getId()` (`book_release_notified`). Same TTL-0 store call.
Minor note: `BookReleaseDTO::getId(): int` is declared non-nullable while the DTO's
constructor allows `?int $id = null`; the job now depends on a non-null provider id
(pre-existing DTO signature; Hardcover GraphQL always returns `id`, so low risk).

TASK-3: PASS
notes: `app/Api/Libraries/Jobs/GrabMovieReleases.php` — crash fix confirmed:
`MovieReleaseDTO` has no `getReleaseDate()` (verified), so the old code path was fatally
broken. Now keyed on `MovieReleaseDTO::getId()` (`movie_release_notified`); no remaining
references to `movie_release_last_notification` (grep verified) — no caller depends on the
old timestamp key. Notification passes `releaseDate: ''` (disclosed in implementation.md;
renders an empty date — acceptable, movies have no date in the DTO).

TASK-4: PASS
notes: `app/Api/Libraries/Tests/GrabReleasesTest.php` — 8 tests covering (a) release
already in set is not re-notified, (b) new release is notified and added to the
long-term set, (c) movie path no longer crashes and dedups on the movie id. Mocks match
the real service signatures (`DeezerService::searchArtists`/`getNewReleases`,
`HardcoverService::getNewReleases`, `TmdbService::getReleasesForGenres`); cache keys match
`UserCacheService::getKey()` (`music_release_notified_<userid>`); `longterm` store is
array-backed in tests via `tests/TestCase.php` (already on main). Tests not executed here
(session restricted to git read/commit); static analysis finds them correct.
Nits (non-blocking): movie test uses IMDb-style `'tt123'` while production `MovieReleaseDTO`
ids are TMDB numeric ids coerced to string — dedup behavior is still validated; the third
music test is functionally identical to the second.

Supporting change (`app/Shared/Services/UserCacheService.php`): PASS — `$storeName`
constructor param defaults to `user_data` (all ~40 container-injected callers unaffected);
`store()` maps TTL ≤ 0 to `forever` — necessary, since Laravel `Repository::put` deletes the
key for seconds ≤ 0. Verified via grep that no other caller passes a non-positive TTL.

Scope: PASS — diff is limited to the three jobs, `UserCacheService`, the new test file and
job docs. Pint style normalization is confined to the touched files (disclosed). No
out-of-scope refactoring.

Commit discipline: PASS for the implementation (`[issue] TASK-N:` per task + separate
style and implementation commits; `implementation.md` has its own commit). One gap, see
blockers below.

## Security

None. No secrets touched; the `longterm` cache store and `persistent_cache` Redis
connection were already introduced on `main` (not by this branch). `.env`/`.env.testing`
contents were not committed (the working-tree `.env.testing`/`AGENTS.md` modifications are
uncommitted and will not merge).

## Overall

NEEDS WORK

The fix itself is correct, complete and matches tasks.md exactly — the reported
"same release re-notified every day" bug is resolved for all three domains via durable
exactly-once dedup that survives deploys. One blocker before merge:

1. Commit the full `docs/jobs/issue_future-releases/tasks.md` (the analyst's task
   breakdown, currently an uncommitted working-tree change — only the 18-line scaffold is
   committed). Without it, the merged branch would carry an empty task breakdown for this
   job, losing the documented root-cause analysis and the approved decision.

Everything else listed above is informational only.