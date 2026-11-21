# Verdict: auto-detect rss feeds

id: adult
reviewer: reviewer
date: 2026-11-21

## Review

Reviewed `git diff main...HEAD` (branch `feature/adult_auto-detect-rss-feeds`).
`composer test`: exit 0, no failures (2040 warnings, all from the missing
`/workspace/.env` in this worktree: `file_get_contents(/workspace/.env)`).
`composer analyse`: exit 0, no errors.

TASK-1: PASS
notes: Root cause documented: the first discovered feed (`/feed/`) is valid
but has no items, so `getFeedData()` returned false. Fixture
`tests/Fixtures/angak_actualites.html` holds the three absolute alternate
links. `FeedReaderTest` › "picks the discovered feed under the page path when
the first one is empty" covers it with `feed_empty.rss` on `/feed/`. No UA
spoofing.

TASK-2: PASS
notes: `FeedReader::getFeedData()` returns `url` (`subscribe_url(true) ?? $url`).
`preferredDiscoveredFeed()` matches on host and on the page-path prefix, and
refetches only when the match differs from the feed SimplePie loaded. The
refetch goes through `fetchFeed()`, so the guard still applies. All four
required tests are present, plus a redirect case. The microformats branch,
which would add the page itself to `all_discovered_feeds`, can't run: `mf2/mf2`
is not installed.

TASK-3: PASS
notes: `FeedService::subscribe()` follows the planned order. The limit check
still runs before the fetch. The resolved-URL lookup reuses the row or returns
409 through `ensureNotSubscribed()`. Data without `url` (OPML) falls back to
the submitted URL. All three `FeedApiTest` cases and the OPML `FeedServiceTest`
case are present.

TASK-4: PASS
notes: `getTestFeedItems()` uses `getFeedData()`. It returns stored items for
the resolved URL when they exist. The item shape is unchanged (§13.2).
Tests cover both the stored and the live path.

TASK-5: PASS
notes: Description text added to the `url` property of both request bodies in
`FeedController.php`. Annotation-only change.

Scope: the diff touches only the files listed in tasks.md, plus job docs.
Standards: the new prose docblocks explain non-obvious behaviour (§10.1) and
match the existing style of `FeedReader`/`FeedService` (§11.1). No
formatting-only hunks. The response contract is unchanged, and the client
contract tests are green (§13).

## Security

Not run separately. Discovery fetches stay behind `OutboundUrlGuard`, and a
test checks that a blocked candidate is never requested.

## Overall

APPROVED

No blockers.
