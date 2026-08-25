# Tasks: future releases

id: issue
status: open
analyst:
date: 2026-08-25

<!-- Produced by @analyst from brief.md. -->

## Root cause

All three release-notification jobs (`GrabMusicReleases`, `GrabBookReleases`,
`GrabMovieReleases`) notify a user when `release->getReleaseDate() >
lastNotification`, where `lastNotification` is a moving window (`now()` of the
previous run). For a future-dated release the release date is always greater
than any past `lastNotification`, so the condition is always true and the same
release is re-notified on every run — the reported bug.

## Decision (exactly-once via durable cache, no per-user setting)

A per-user "upcoming vs. new" setting was considered and rejected (UI/UX
surface, doesn't fix the bug). Instead, notification is keyed on *which
releases were already notified*, and — critically — that state must **survive
deploys**. A dedicated long-term (non-wiping) cache will be introduced so the
dedup state persists across deploys (confirmed with product; no new database
table or migration needed).

- Store, per user and per domain (music|book|movie), a cache set of the release
  IDs already notified. Reuse the existing `UserCacheService` pattern the jobs
  already use, replacing the current `*_release_last_notification` timestamp
  keys with `*_release_notified` ID-set keys.
- Notify a release only if its ID is not already in the set; after notifying,
  add its ID to the set.
- The release date is entirely disregarded — no future/upcoming distinction,
  which sidesteps that debate and leaves the releases feed/list untouched
  (users who like browsing upcoming items keep seeing them).
- All DTOs have stable `getId()` keys (Music/Book = int provider id, Movie =
  string TMDB id), so one flow serves all three domains. The movie job no
  longer needs a date at all, fixing its current `getReleaseDate()` crash.

## Task breakdown

TASK-1: Switch the music notification to exactly-once: read the cached set of
     notified release IDs for the user, notify only IDs not in the set, then
     store the updated set. Remove the date comparison and the
     `music_release_last_notification` timestamp usage (new key, e.g.
     `music_release_notified`).
     files: app/Api/Libraries/Jobs/GrabMusicReleases.php
     depends: none
     risk: low — small, self-contained job change; feed/caching of the release
            list itself is unchanged.

TASK-2: Apply the identical exactly-once change to the book notification job
     (new key `book_release_notified`, key on `BookReleaseDTO::getId()`).
     files: app/Api/Libraries/Jobs/GrabBookReleases.php
     depends: TASK-1 (same pattern; can be done together)
     risk: low — same change as TASK-1.

TASK-3: Apply the exactly-once change to the movie notification job, keying on
     `MovieReleaseDTO::getId()`. This also fixes the current crash where the
     job calls `$release->getReleaseDate()` (not present on `MovieReleaseDTO`).
     Movie "coming soon" notices are pushed once per movie and never repeated.
     files: app/Api/Libraries/Jobs/GrabMovieReleases.php
     depends: TASK-1 (same pattern)
     risk: medium — the movie path currently crashes, so this defines previously
            broken behavior; confirm no caller depends on the old timestamp key.

TASK-4: Add/extend Pest coverage: (a) a release already in the notified set is
     not re-notified on subsequent runs; (b) a new release not in the set is
     notified and then added to the set; (c) the movie path no longer crashes
     and dedups on movie id.
     files: app/Api/Libraries/Tests/LibraryServiceTest.php
            app/Api/Libraries/Tests/ (new job tests, e.g. GrabMusicReleasesTest)
     depends: TASK-1, TASK-2, TASK-3
     risk: low — follows existing test patterns; jobs are easily testable against
            the mocked cache/service.

## Out of scope / open questions

- The long-term (non-wiping) cache that will hold the notified-set is being
  introduced separately by product/infra; this work only consumes it via
  `UserCacheService`. The notified-set keys should be stored with a long/no TTL
  so they outlive the release's presence in the provider feed.
- No per-user "upcoming vs. new" setting; the exactly-once fix satisfies the
  reported "notify me about New Releases" request without taking upcoming items
  away from anyone (feed/list behavior is untouched).
- Whether the "releases" feed should visually separate "upcoming" from "just
  released" is a product/UI concern outside this bug fix.
