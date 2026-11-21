# Tasks: auto-detect rss feeds

id: adult
analyst: analyst
date: 2026-11-21

## Context (read first)

Autodiscovery partly exists already. `FeedReader::fetchFeed()` hands the URL
to SimplePie with its default locator (`LOCATOR_ALL`), and SimplePie fetches
through our guarded `httpClient()`. `FeedReaderTest` › "resolves an
autodiscovered feed link against the URL after redirects" proves that a page
with `<link rel="alternate" type="application/rss+xml">` already gets resolved.

So the job has three gaps:

1. **Unknown failure.** We don't yet know why `https://angak.fr/actualites/`
   fails today. The analyst could not fetch the raw HTML/headers (no shell),
   and `vendor/` is not installed in this worktree.
2. **Wrong candidate.** SimplePie returns the first working candidate
   (`https://angak.fr/feed/`, the whole-site feed). The user pasted the
   section page, so `https://angak.fr/actualites/feed/` is the feed they
   expect.
3. **Page URL is stored.** `subscribe()` stores the page URL in `feeds.url`.
   That means every hourly sync repeats discovery (extra fetches), and the
   duplicate check (`Feed::where('url', …)`) misses a feed already stored
   under its real feed URL.

## Task breakdown

TASK-1: Reproduce the angak.fr failure, find its root cause, and fix it if the cause is in `FeedReader`.
  - Fetch `https://angak.fr/actualites/` with SimplePie's default user agent
    (`Misc::get_default_useragent()`) and compare status, headers and `<head>`
    against what SimplePie's `Locator` needs. Likely suspects:
    - a WAF/host returning 403 to the SimplePie UA;
    - the page sniffing as something other than `text/html`;
    - a candidate status outside 200/207–299.
  - Save a trimmed copy of the page head as a fixture
    (`tests/Fixtures/angak_actualites.html`, with the three absolute
    `<link rel="alternate">` tags from the brief). Add a `FeedReaderTest` case
    that fails before the fix.
  - If the cause is outside our code and can't be fixed (e.g. hard bot
    blocking of every non-browser client), stop and report. Do not add UA
    spoofing without approval.
  files: app/Api/Feeds/Services/FeedReader.php, app/Api/Feeds/Tests/FeedReaderTest.php, tests/Fixtures/ (new HTML fixture)
  depends: none
  risk: medium — the root cause is unknown, so the size of the fix is unknown too.

TASK-2: Make `FeedReader::getFeedData()` return the resolved feed URL, preferring the discovered candidate under the page's own path.
  - Add `'url'` to the returned array. Use `$pie->subscribe_url()` when the
    given URL was already a feed.
  - When autodiscovery found several feeds (`$pie->get_all_discovered_feeds()`),
    choose in this order:
    1. the first candidate whose path starts with the page URL's path
       (`/actualites/` → `/actualites/feed/`);
    2. otherwise the first candidate, which is SimplePie's current choice.

    Only refetch the chosen candidate if it differs from what SimplePie
    already loaded.
  - All fetches must still go through `fetchMany()`/the `OutboundUrlGuard`.
    Discovery must not open a path that skips the guard.
  - Tests:
    - the angak-style fixture picks `/actualites/feed/`;
    - a page with a single link still works;
    - a direct feed URL returns itself as `url`;
    - discovered candidates on a blocked address are never fetched.
  files: app/Api/Feeds/Services/FeedReader.php, app/Api/Feeds/Tests/FeedReaderTest.php
  depends: TASK-1
  risk: medium — it changes which feed existing page-URL inputs resolve to, and adds one fetch when the preferred candidate differs.

TASK-3: Store the resolved feed URL on subscribe, and run the existing-feed and already-subscribed checks against it.
  - In `FeedService::subscribe()`:
    1. Keep the cheap lookup by the submitted URL.
    2. If no row matches, fetch, then look up `Feed` by `$feedData['url']`.
    3. If that row exists, reuse it and apply the already-subscribed check
       (409).
    4. Otherwise create the `Feed` with the resolved URL.
  - Keep the subscription-limit check before any fetch.
  - `createImportedSubscription()` (OPML) passes `parseFeedData()` output,
    which has no `url`. Fall back to the submitted URL there. OPML behaviour
    must not change.
  - `FeedApiTest` cases:
    - subscribing with a page URL stores the feed URL;
    - subscribing with a page URL whose feed is already stored reuses that
      row;
    - …and answers 409 when the user already has that feed.

    Existing tests stay green.
  files: app/Api/Feeds/Services/FeedService.php, app/Api/Feeds/Tests/FeedApiTest.php, app/Api/Feeds/Tests/FeedServiceTest.php
  depends: TASK-2
  risk: medium — it reorders the lookup/fetch in the subscribe path that both the API and the OPML import share.

TASK-4: Make `POST /feeds/test` work for page URLs: look up an already-stored feed by the resolved URL before returning live items.
  - Change `getTestFeedItems()` to use `getFeedData()` (or an equivalent that
    exposes `url`) instead of `getItems()`. Response shape stays the same:
    the same array of up to 5 items, per STANDARDS §13.
  - Test: a page URL whose discovered feed is already stored returns the
    stored items without fetching the feed again.
  files: app/Api/Feeds/Services/FeedService.php, app/Api/Feeds/Tests/FeedApiTest.php
  depends: TASK-2
  risk: low — read-only endpoint with an unchanged response contract.

TASK-5: Update the OpenAPI annotations so `url` on `/feeds/test` and `/feeds/subscriptions` documents that a website URL is accepted and resolved to its feed.
  files: app/Api/Feeds/Controllers/FeedController.php
  depends: TASK-3, TASK-4
  risk: low — text-only annotation change (STANDARDS §2.6).

## Not in scope

- A separate "discover" endpoint that returns all candidates for a picker UI.
  See open question 1.
- Rewriting existing `feeds.url` rows that hold page URLs. They keep working,
  because sync still autodiscovers.
- OPML import discovery (`OpmlImportService` parses downloaded content and
  never autodiscovers).
- Frontend changes (separate repo).

## Open questions (need a human answer; the plan above assumes the defaults)

1. **Picker or auto-pick?** The brief lists three discovered feeds but doesn't
   say which one gets subscribed. This plan auto-picks on the backend (path
   match, else first), so no frontend change is needed. If the frontend
   should instead show a choice, as Feedly/Miniflux do, we need a new additive
   endpoint (e.g. `POST /feeds/discover` → `[{url, title}]`) plus frontend
   work. That replaces most of TASK-2's heuristic.
2. **Return the resolved URL from `/feeds/test`?** It would be additive under
   `meta`, so the UI could show "subscribing to …/actualites/feed/". It is not
   planned unless asked.
3. **If TASK-1 shows angak.fr blocks non-browser user agents:** do we accept
   that, or approve a different UA for fetches?
