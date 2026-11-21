# Implementation: auto-detect rss feeds

id: adult
developer: developer
date: 2026-11-21

## Summary

Root cause (TASK-1): `https://angak.fr/actualites/` is not blocked. It serves
200 `text/html` to the SimplePie UA, and autodiscovery already ran. SimplePie
picks the first `<link rel="alternate">`, which is `https://angak.fr/feed/`.
That is a valid RSS feed with **zero items**, so `getFeedData()` returned
false and the subscription failed. `https://angak.fr/actualites/feed/` has 10
items.

What changed:
- `FeedReader::getFeedData()` now prefers a discovered feed on the same host
  under the page's path.
- It returns the resolved feed `url`.
- Subscribe stores that URL and dedups against it.
- `POST /feeds/test` serves stored items for the resolved feed.

Checked live: `getFeedData('https://angak.fr/actualites/')` returns
`https://angak.fr/actualites/feed/` with 10 items. `composer test` and
`composer analyse` both exit 0. The ~2000 test warnings all come from the
missing `.env` in this worktree.

## Changes

TASK-1: Added the fixture `tests/Fixtures/angak_actualites.html` (trimmed
head with the three absolute alternate links). Added a `FeedReaderTest` case
in which `/feed/` is empty. It failed before the fix. Fix in `FeedReader`:
`preferredDiscoveredFeed()` picks the first candidate from
`get_all_discovered_feeds()` on the page's host whose path starts with the
page path plus `/`. Root pages (`/`) keep SimplePie's choice. The chosen feed
is refetched through `fetchFeed()` (guarded) only when it differs from what
SimplePie loaded.

TASK-2: `getFeedData()` returns `'url' => $pie->subscribe_url(true) ?? $url`.
`httpClient()` reports every redirect as a 307, so this is the URL before
redirects: the submitted URL for a direct feed, the linked href for a
discovered one. New `FeedReaderTest` cases cover:
- the angak fixture resolves to `/actualites/feed/`;
- a page with a single link;
- a direct feed URL returns itself;
- a discovered candidate on a blocked address is never requested;
- `url` after a page redirect.

TASK-3: Changed `FeedService::subscribe()`. The order is now:
1. Look up the Feed by the submitted URL. If found, run the
   already-subscribed check.
2. Check the subscription limit (still before any fetch).
3. Fetch.
4. Look up the Feed by `$feedData['url']`. Reuse it with the 409 check, or
   create it with the resolved URL.

Data without `url` (OPML `parseFeedData()` output) falls back to the
submitted URL. The duplicate check is extracted into `ensureNotSubscribed()`.
Tests:
- `FeedApiTest`: stores the feed URL, reuses an existing row, 409 when
  already subscribed.
- `FeedServiceTest`: OPML import keeps its URL.

TASK-4: `getTestFeedItems()` now uses `getFeedData()`. When the resolved URL
differs and that Feed is stored with items, it returns the stored items. The
stored-items mapping moved into `storedTestFeedItems()`. The response shape
is unchanged (the controller still slices to 5). Tests: a stored resolved
feed is returned without `getItems()`, and live items come back for the
angak fixture.

TASK-5: The `url` request property on `POST /feeds/test` and
`POST /feeds/subscriptions` now documents that a website URL is accepted
(`FeedController.php`).

## Known issues / follow-ups

- When no candidate matches the page path, the first candidate is still
  used, even if it is empty. Pasting `https://angak.fr/` therefore still
  fails, because its first link is the empty `/feed/`. A follow-up could skip
  candidates without items. All candidates are already downloaded during
  discovery, so this could be done without extra fetches.
- Path matching uses the submitted URL. If a page redirects to another host
  (e.g. adding `www.`), the matching falls back to the first candidate.
- If the user is at the subscription limit and submits a page URL whose feed
  they already have, they get 422 (limit) instead of 409. The limit check has
  to run before the fetch that resolves the URL.
- `php artisan l5-swagger:generate` fails in this worktree with
  "Required @OA\Info() not found". This looks environmental (no `.env`); it
  was not investigated.
- `vendor/` was installed from the lock file (`composer install`), and
  `storage/framework/*` was created so artisan runs. Both are git-ignored.
  `.env.testing` and the untracked `docs/backlog`/`docs/reports` files were
  already in the worktree status (hub mounts). They were left untouched and
  not committed.
