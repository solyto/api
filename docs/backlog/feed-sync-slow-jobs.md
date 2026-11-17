# Backlog: slow feed sync jobs (sequential og:image lookups, feed timeouts)

created: 2026-11-17

## What

Some `SyncFeed` jobs take far longer than one feed fetch can: up to 142 s.
The likely cause is the sequential `og:image` lookup for new items. Feeds
that time out on every few syncs add to it. Make a single feed's sync
bounded and short.

## Findings

From Laravel Pulse on production (1.1.3, past 7 days, 1000 ms thresholds):

- **Slow Jobs:** `SyncFeed` 903 slow runs, slowest **142,692 ms**.
  `GrabMusicReleases` 2 runs, slowest 26,771 ms (daily, external APIs;
  unremarkable).
- **Slow Outgoing Requests:** only feed URLs, all at the fetch timeout:

  | URL | Count | Slowest |
  |---|---|---|
  | `https://undergroundhiphopblog.com/feed/` | 13 | 10,003 ms |
  | `https://github.com/Freika/dawarich/tags.atom` | 5 | 10,004 ms |
  | `https://developer.chrome.com/static/blog/feed.xml` | 4 | 10,004 ms |
  | `https://www.gsmarena.com/rss-news-reviews.php3` | 2 | 10,003 ms |

- **Queues:** the hourly batch (~393 `SyncFeed` jobs from `SyncFeeds`) is
  processed within its time slot. Failed 0, Released 0. So no backlog builds
  up today, and nothing has hit the job timeout yet.

With ~393 feeds per hour, ~66,000 runs a week, so ~1.4% are slow.

## Cause (from the code, not proven per feed)

- Feed fetch: `FeedReader::FETCH_TIMEOUT = 10` s. This explains the
  10,00x ms requests, but caps one fetch at 10 s.
- `FeedService::syncFeed()` (`app/Api/Feeds/Services/FeedService.php:465`)
  calls `FeedReader::extractImageUrl()` for every **new** item. When the
  feed item has no media thumbnail or content, this falls back to
  `extractOgImage()` (`FeedReader.php:419`). That loads the article page,
  **one item after another**, with `OG_IMAGE_TIMEOUT = 5` s each.
- A feed with many new items without images (first sync after a
  subscription or OPML import, or a very active feed) adds up: 30 slow pages
  × 5 s = 150 s. Article pages under 1 s each never appear in Slow Outgoing
  Requests, but 100 of them still add up to over 100 s.
- Upper bound: `SyncFeed::$timeout = 240` s (`WithoutOverlapping` lock
  300 s, `$tries = 2`). About 48 new items that all hit the 5 s limit would
  exceed it, and the job would be killed and counted as failed.

Pulse does not show which feed a slow job belonged to, so the 142 s run is
not attributed to a specific feed.

## Options

1. **Fetch the og:image pages in parallel.** `FeedReader::fetchMany()`
   already takes several URLs and uses `Http::pool`. `extractOgImage()`
   calls it with one URL per item. Collect the new items' links in
   `syncFeed()` first, then fetch them in one (or a few chunked) calls. The
   worst case drops from N × 5 s to about 5 s per chunk. Mind the pool size
   (many parallel requests to the same host) and the existing URL guard
   (`OutboundUrlGuard`, pinning).
2. **Cap the lookups per sync.** At most X `og:image` lookups per run; the
   rest are picked up later (`syncFeed(..., backfillImages: true)` exists).
3. **Back off feeds that keep timing out.** Skip a feed for a while after
   several timeouts in a row. Today `recordSyncFailure()` only stores the
   last error (`feeds.last_error`), no count or timestamp, so this needs a
   new column or cache counter.
4. **Measure first.** Log the duration and number of og:image lookups per
   `SyncFeed` run (`queue` log channel), to confirm the cause and find the
   feeds behind the slow runs before changing anything.
   A custom Pulse recorder per feed would show the same in the dashboard
   (option B in `monitoring-improvements.md`). Grouping Pulse's slow outgoing
   requests by host was considered and rejected: it loses the exact feed URL
   and still misses lookups under 1 s.

The options can be combined (e.g. 4 first, then 1 or 2; 3 independently).

## Notes

- Not urgent: no failures, no queue backlog, and the slow runs only occupy
  workers longer. It matters more with more feeds, fewer workers, or a big
  OPML import.
- Related: `opml-import-job-timeout.md` (the OPML import fetches feeds
  sequentially too).
