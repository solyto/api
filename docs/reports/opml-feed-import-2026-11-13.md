# Report: Importing RSS feeds from an OPML file

date: 2026-11-13
read: true
branch: `report/quickly_import-opml-rss-feeds`, tree at `8a8652c`
scope: Whether and how users can import their feed subscriptions from an OPML file. Written for the maintainer. No application code, config, routes or migrations were changed. Two throwaway PHP probes (libxml behaviour) were run outside the repo and then deleted.

## 1. Summary

**Yes, we can do it, and most of the parts already exist.** OPML parsing already lives in the `app:feeds:import` command (`ImportFeeds.php:102-130`). Per-user subscribing already exists (`FeedService::createSubscription`, `FeedService.php:83-120`). The import flow pattern already exists in the Calendar/Contacts import (`start` → `select` → `state`, queued job, state in the cache). What's missing is an endpoint, a parser that handles real exports, and a few safety limits.

Three things shape the design:

1. **Subscribing one feed costs a blocking HTTP fetch** (`FeedService.php:93`, SimplePie default timeout of 10 s, up to 5 redirects). With an OPML file of 200 feeds, that can't run inside a request. It has to run in a queued job.
2. **There is no SSRF guard on feed URLs.** `FeedReader` fetches any URL (`FeedReader.php:18-47`). The `OutboundUrlGuard` that already exists (`app/Shared/Services/OutboundUrlGuard.php`) is only used by `RemoteCalDavClient`. This is already a problem for single subscriptions (see `bot-traffic-detection-2026-10-14.md` §2 and `feed-retention-reingest-churn-2026-11-12.md` §4). An OPML import makes it worse: one upload means hundreds of server-side requests to URLs the user chose.
3. **Parsing is safe as long as nobody adds `LIBXML_NOENT`.** The current flags don't resolve external entities, and libxml blocks entity-expansion bombs. With `LIBXML_NOENT` added, a local file can be read (probe, §5.1).

**Recommendation (§4):** a three-step import that mirrors the Calendar import:
- `POST feeds/import` uploads the file, parses it, stores the state and returns a preview.
- `POST feeds/import/select` takes the chosen URLs and dispatches the job.
- `GET feeds/import/state` returns progress and results.

Each feed is validated by the existing `createSubscription`, so invalid URLs never reach the shared `feeds` table. The parser moves out of the command into a service, so the command and the endpoint share it.

Before this goes live, the SSRF guard should be wired into `FeedReader`. That is a separate job, and it fixes single subscriptions too. Folders are flattened for now. Keeping them needs a migration and is a human decision (§9).

Estimated effort: about 2 days for the import itself, plus about 1 day for the `FeedReader` guard (§8).

## 2. Current state

### 2.1 Feeds and subscriptions

- **`feeds`** is a global table shared by all users: `title`, `url`, `created_by`, `last_synced_at`, `last_error` (`Feed.php:25-31`). `url` is `string` (255 characters, `2025_10_06_160348_create_feeds_table.php:17`) and has **no unique index**.
- **`feed_subscriptions`** is per user: `title`, `whitelist`, `blacklist`, `feed_id`, `user_id` (`FeedSubscription.php:117-123`, migration `2026_02_20_134424_create_feeds_list_table.php:14-22`). There is **no unique index on `(user_id, feed_id)`** and **no folder or category column**.
- **Subscribing:** `POST feeds/subscriptions` (`routes/api.php:394`) goes through `StoreFeedSubscriptionRequest` (`title` required, `url` `required|string|url`, no `max:255`) and then `FeedService::createSubscription` (`FeedService.php:83-120`):
  1. If the user already subscribes to a feed with this exact URL, it throws `FeedAlreadySubscribedException` (`:88-90`). The controller maps that to 409 (`FeedController.php:341-343`).
  2. If no `Feed` row exists, it fetches the URL **synchronously** with `FeedReader::getFeedData` (`:93`). If that returns no items, it throws `FeedException`, which maps to 422 (`FeedController.php:344-346`). Otherwise it creates the `Feed`, using the feed's own title (`:99-103`).
  3. It creates the subscription, forgets the `user_feeds` cache and dispatches `SyncFeed` (`:106-117`).
- **Sync:** `SyncFeeds` runs hourly (`routes/console.php:8`) and dispatches one `SyncFeed` per feed that has at least one subscription (`SyncFeeds.php:36`). `SyncFeed` is `WithoutOverlapping` per feed, with `$tries = 2` and `$timeout = 240` (`SyncFeed.php:17-40`). A failing feed gets `last_error` set (`FeedService::recordSyncFailure`).
- **Global visibility:** `GET feeds/available` and `GET feeds/search` list **every** `Feed` row, for every user (`FeedService.php:279-295`). So any row an import creates becomes visible to all users.
- **Fetching:** `FeedReader` hands the URL straight to SimplePie (`FeedReader.php:18-47`). SimplePie 1.9.0 (`composer.lock`) defaults to a 10 s timeout, up to 5 redirects (`File.php:89`), and full autodiscovery (`SimplePie.php:552`, `LOCATOR_ALL`, up to 10 candidate URLs checked, `:566`). Besides that, `extractOgImage` fetches every new item's page (`FeedReader.php:157-186`).

### 2.2 The existing OPML parser (`ImportFeeds`)

`app:feeds:import` (`ImportFeeds.php`) is an admin seeding tool. It downloads 46 hard-coded OPML files from `plenaryapp/awesome-rss-feeds` (`:38-85`) and puts each entry into the global `feeds` table through `FeedService::addFeed` (`FeedService.php:35-49`). It does **not** create subscriptions and does **not** fetch the feeds.

`parseOpml()` (`ImportFeeds.php:102-130`):

| What it does | Reusable? | Gap |
|---|---|---|
| Escapes stray `&` before parsing (`:104`) | Yes. Real exports contain unescaped `&` in URLs. Without the fix, libxml's recovery silently truncates `?a=1&b=2` to `?a=1=2` (probe, §5.1). | — |
| `simplexml_load_string` with `LIBXML_RECOVER \| NOERROR \| NOWARNING` (`:107`) | Yes. No `LIBXML_NOENT`, so it is safe (§5.1). | Doesn't reject `<!DOCTYPE`, which OPML never needs. |
| Reads `$xml->body->outline` (`:120`) | Partly | **Only top-level outlines.** Feeds inside folders are dropped. Most readers export folders (§3). |
| Reads `title` and `xmlUrl` (`:122-124`) | Partly | **No fallback to `text`.** In OPML, `text` is the required attribute and `title` is optional. FreshRSS exports only `text` (§3), so every title would come out as `""`. Variants such as `xmlURL` and `url` are not read either. |
| Doesn't filter out outlines without `xmlUrl` | No | Folder outlines would become feeds with an empty URL. |
| Throws `\RuntimeException` on unparseable input (`:113`) | Needs a typed exception | Would reach the API as a 500 (STANDARDS 7.2). |

### 2.3 Feed endpoints today

`routes/api.php:385-399`: `items`, `available`, `search`, `friends`, `test` and the `subscriptions` CRUD routes. There is no import endpoint. No feed route has a rate limiter. The named limiters live in `AppServiceProvider.php:58-94`.

## 3. The OPML format and real-world exports

**Spec (OPML 2.0, [S1]).** The root is `<opml version="…">`, which contains a `<head>` and a `<body>` with one or more `<outline>`. Every outline **must** have `text`. Subscription-list outlines have `type="rss"`, `text` and `xmlUrl`, with optional `title`, `htmlUrl`, `description`, `language` and `version`. The spec says `text` "should initially be the top-level title" of the feed, but it is user-editable. It also says subscription lists may be "arbitrarily structured" (nested folders). The optional `category` attribute holds comma-separated slash paths (`/Tech/PHP`). Version 1.1 files are treated as 1.0.

**What real readers export and accept** (from their source code):

| Reader | Export shape | How its importer detects a feed | Title precedence | Source |
|---|---|---|---|---|
| Miniflux | One folder level per category, feeds inside. Every feed has `title`, `text`, `xmlUrl`, `htmlUrl` and `description`, plus `miniflux:*` attributes for its own rules. | `xmlUrl` present (non-blank). Otherwise it recurses into children. | `title` → `text` → `htmlUrl` → `xmlUrl` | [S2] |
| FreshRSS | Category folders. Feeds have **`text` only (no `title`)**, `xmlUrl`, `htmlUrl`, `description`, `frss:*` attributes, and `type` values such as `HTML+XPath` or `JSONFeed` for non-RSS sources. | `xmlUrl` present. Recurses into children of any depth. Also reads the `category` attribute. | `text` → `title` | [S3] |
| NewsBlur | Nested folders (`text` + `title`), arbitrary depth | `xmlUrl` **or `xmlURL`** | `title` → `text` → `htmlUrl` → `xmlUrl` | [S4] |
| Feeder (Android) | Tag folders. Feeds have `title`, `text`, `type="rss"` and `xmlUrl`. **Non-outline elements (`feeder:settings`) inside `<body>`.** | — | — | [S5] |
| Thunderbird | Folders | `type="rss"` **required**. URL from `xmlUrl` **or `url`**. | `text` → `title` → `xmlUrl` | [S6] |
| NetNewsWire (RSParser) | — | Reads `text`, `title`, `xmlUrl`, `htmlUrl` and `type` from any outline at any depth (SAX parser) | — | [S7] |
| Feedly | Exported at `feedly.com/i/opml`. Only public RSS feeds; boards, AI feeds and Reddit feeds are excluded. | — | — | [S8] (folder structure not documented there) |
| Inoreader | Imports and exports OPML, and can also *subscribe* to a remote OPML URL and resync it periodically | — | — | [S9] |

**Takeaways for our parser**

- **Recurse.** Treat any outline with a feed URL as a feed and recurse into all others. The URL is `xmlUrl`, else `xmlURL`, else `url`. Don't require `type="rss"`: Miniflux and FreshRSS don't rely on it, and readers that do (Thunderbird) also write it.
- **Title:** use `title`, then `text`, then the URL. Strip tags and decode entities, since `text` may contain encoded HTML [S1]. `FeedService::plainTitle` already does exactly this, but it is private. Truncate to 255 characters (the `feed_subscriptions.title` column).
- **Ignore unknown elements** inside `<body>` (Feeder) and vendor-namespaced attributes (`miniflux:*`, `frss:*`). Miniflux's keep/block rules are regexes, which don't map to our comma-separated keyword `whitelist`/`blacklist` (`FeedService.php:199-206`), so they are dropped.
- **Skip unsupported FreshRSS `type`s** (`HTML+XPath`, `XML+XPath`, `JSON+DotNotation`, `JSONFeed`, …), or let feed validation reject them. Their `xmlUrl` is a web page, not a feed.
- **Deduplicate URLs within a file.** The same feed often appears in two folders.
- **Encoding:** libxml honours the XML declaration (an ISO-8859-1 file decodes correctly) and accepts a UTF-8 BOM (probe).
- **Size:** an OPML entry is roughly 150–400 bytes. 1 MB holds several thousand feeds, which is far more than a personal list. (This estimate is mine, not from a source. FreshRSS's default per-user cap is 131,072 feeds [S3], so other readers don't limit this in a meaningful way.)

## 4. Options

### 4.1 Where the work happens

| | A. Synchronous: parse and subscribe in the request | B. Create rows now, let `SyncFeed` validate | C. Upload → preview/select → queued job → poll (recommended) |
|---|---|---|---|
| Pattern in codebase | `NotesImportExportController::import` (`:60-80`), small and local only | — | Calendar/Contacts `ImportController` (`startImport` / `selectCalendars` / `getState`, `Calendars/Controllers/ImportController.php:62,108,145`), state in the cache (`CalendarImport.php:210-218`), `ImportCalendars` job |
| Request time | N × fetch (10 s timeout, redirects, autodiscovery). 100 feeds can mean minutes, so it times out. | Fast. One insert per entry, no fetch. | Fast. Parse only. |
| Invalid URLs | Rejected | **Stored in the shared `feeds` table** and shown to every user in `available`/`search` (§2.1) until someone cleans them up. Only `last_error` marks them. | Rejected by the existing `createSubscription` check, never stored |
| User feedback | Full result, if it finishes | "Imported", followed by silent failures | Preview before import. Progress and a per-feed result list afterwards. |
| New code | Least | Little, but would need a new "unvalidated" state for `Feed` to keep it out of the shared lists | One service, one job, three actions, two requests, one resource |

**A** doesn't work for real files. **B** pollutes data shared by all users, and fixing that needs a schema change. **C** reuses an existing flow and validates every feed with the code path single subscriptions already use.

### 4.2 Preview/select step or "import all"

The Calendar import asks the user what to import. For OPML that is useful too: exports from Feedly or Inoreader often contain hundreds of stale feeds, and users like to tick boxes. The cost is one extra round trip and some frontend UI. Without the step, `POST feeds/import` would dispatch the job directly and `select` would go away. I recommend keeping it. It is the established pattern, and the frontend can still offer "select all" by default.

### 4.3 Validating each feed

`createSubscription` already does the right thing: it skips feeds the user already has (`FeedAlreadySubscribedException`), validates new feeds by fetching them (`FeedException`), and reuses existing `Feed` rows without a fetch. The job calls it once per selected entry, catches these two typed exceptions, and records the outcome per URL (`subscribed` / `already_subscribed` / `invalid`).

**Job shape.** One job that loops over N entries is the `ImportCalendars` shape, which caps itself at 1800 s (`ImportCalendars.php:31`). For feeds, a single slow or dead host costs up to ~10 s per redirect hop. One alternative is to fan out one small job per entry, as `SyncFeeds` does (`SyncFeeds.php:36-41`). Then one bad feed can't starve the rest, but progress has to be counted with `Cache::increment` instead of a single state object. I recommend the single job with an explicit `$timeout` (STANDARDS 9.2) and the per-file cap from §5.2. That keeps the worst case bounded (500 × ~10 s, a bit over an hour, in practice far less) and matches the existing import. Fan-out is the fallback if the measured time turns out too long.

**Race.** `feeds.url` has no unique index (§2.1). Two imports, or one import and a manual subscribe, can create the same new feed at the same moment and end up with duplicate `Feed` rows. This already exists today for manual subscriptions. Imports make it more likely. The options are a unique index (migration on the default MariaDB connection, ⚑ human decision) or a cache lock keyed on the URL hash around the "find or fetch+create" step.

## 5. Security and abuse

### 5.1 XML parsing (XXE, entity expansion)

Probed on this container (PHP 8.4.27, libxml 2.9.14), using the exact flags from `ImportFeeds.php:107`:

| Payload | Current flags | With `LIBXML_NOENT` added |
|---|---|---|
| External entity `SYSTEM "file:///…"` in element content | **Not resolved** (empty) | **File content read** (XXE) |
| External entity in an attribute | Rejected by libxml ("Attribute references external entity") | Same |
| Billion laughs (10 levels) | Aborted: "Detected an entity reference loop", no memory spike | Aborted |
| 300,000 nested outlines | Parsing stops at depth 256 ("use XML_PARSE_HUGE"). With `RECOVER`, a partial tree is returned. | — |
| Unescaped `&` in `xmlUrl`, no pre-escape | `RECOVER` silently corrupts the URL (`?a=1=2`) | — |

Since PHP 8.0 / libxml 2.9, external entity loading is off by default, which is why `libxml_disable_entity_loader` is deprecated. So **the existing parse call is safe as long as nobody adds `LIBXML_NOENT`, `LIBXML_DTDLOAD` or `LIBXML_PARSEHUGE`.** As a defence in depth that costs nothing, refuse any document that contains `<!DOCTYPE` or `<!ENTITY` before parsing (OPML never uses a DTD), and add `LIBXML_NONET`. A test with an XXE fixture locks this in (§7).

### 5.2 Upload size and entry count

- PHP's `upload_max_filesize` here is 2M (CLI value; production is configured in the deployment repo). Validate in the FormRequest: `required|file|max:1024|extensions:opml,xml`. That is 1 MB. Real lists are tens of KB.
- Cap the number of feed entries per file at **500**, after deduplication, and reject the file with 422 above that. (The number is a proposal; see §9.)
- There is still **no cap on subscriptions per user** (`bot-traffic-detection-2026-10-14.md` §2, row 1). The import makes it easy to reach thousands.

### 5.3 SSRF

`FeedReader` passes user URLs to SimplePie with no host check. SimplePie follows redirects and runs autodiscovery, which fetches more URLs found in the response (§2.1). `StoreFeedSubscriptionRequest` only checks the `url` syntax. `OutboundUrlGuard` (`OutboundUrlGuard.php:42-69`) already does the right thing: it resolves the host, requires every address to be public, and returns a `CURLOPT_RESOLVE` pin. `RemoteCalDavClient` shows how to use it (`:332-346`), including re-checking each redirect hop by hand (`:373-391`). **No feed code uses it.**

For OPML this matters more than for a single subscribe. One upload turns into up to 500 server-side requests to addresses the user picked. In effect, the server becomes a scanner of its own internal network, and the results come back through the per-URL status and `feeds/items`.

Recommendation: **wire the guard into `FeedReader` as its own job first, or at the latest together with the import.** That job would check before `set_feed_url`, pin via `set_curl_options([CURLOPT_RESOLVE => …])`, and turn off redirects or re-check them. Doing it in `FeedReader` protects single subscribe, `feeds/test`, hourly sync and the import in one place. `extractOgImage` (`FeedReader.php:157-186`) needs the same guard. The import job maps `BlockedUrlException` to the `invalid` outcome. ⚑ Whether the import may ship before the guard is a human decision (§9).

### 5.4 Rate limiting

Add a named limiter `feeds-import` in `AppServiceProvider` in the same style as `account-deletion` (`:81-83`), for example `Limit::perHour(5)->by($request->user()->id)`, on `POST feeds/import`. Only one import per user can run at a time, because the state key is per user (as in `calendar-import-{userId}`). `start` returns 409 while a state with stage `running` exists.

### 5.5 Effect on shared tables and hourly sync load

- With option C, only feeds that validate are written to `feeds`. They become visible to everyone in `available`/`search`, which is how manual subscriptions already work.
- Each new feed adds one `SyncFeed` per hour (24 fetches a day) plus one `og:image` page fetch per new item. A user who imports 300 new feeds adds about 7,200 feed fetches a day, plus the item page fetches. Today's daily retention churn (`feed-retention-reingest-churn-2026-11-12.md`) multiplies the `og:image` cost for slow-moving feeds. Feeds that already exist add **no** sync load, because the sync is per feed, not per subscriber.
- The first `SyncFeed` for each new feed is dispatched by `createSubscription` (`FeedService.php:117`). An import of 300 new feeds puts 300 jobs on the Redis queue at once, next to the hourly batch. That is acceptable for today's numbers (`SyncFeed` is short and `WithoutOverlapping`), but worth watching in Pulse after launch.

## 6. Recommended approach (API contract)

All routes go inside the existing `feeds` prefix (`routes/api.php:385`), under `auth:sanctum`. They are additive, so older frontends aren't affected (STANDARDS 13.2).

```
POST feeds/import          (multipart: file)             throttle:feeds-import
POST feeds/import/select   { "urls": ["https://…", …] }
GET  feeds/import/state
```

**`POST feeds/import`.** The `ImportFeedsRequest` FormRequest checks `file` (`required|file|max:1024|extensions:opml,xml`). The service:
1. reads the file;
2. rejects `<!DOCTYPE`/`<!ENTITY`;
3. pre-escapes `&` and parses with `RECOVER | NONET` (no `NOENT`);
4. walks the outlines recursively;
5. deduplicates by URL;
6. drops non-http(s) URLs, URLs over 255 characters and unsupported FreshRSS `type`s;
7. marks entries the user already subscribes to;
8. checks the 500-entry cap;
9. stores the state in the cache for 60 minutes (the `CalendarImport::saveState` pattern).

Errors: 422 for an unparseable file, no entries, or more than 500 entries (a typed `FeedImportException`, mapped in the controller as STANDARDS 7.2 allows). 409 while an import is already running. Response `200`:

```json
{
  "success": true,
  "message": "Import started successfully.",
  "data": {
    "stage": "parsed",
    "feeds": [
      { "url": "https://example.com/feed.xml", "title": "Example", "folder": "Tech", "subscribed": false }
    ]
  }
}
```

`folder` is informational only: the frontend can group the preview by it, and nothing stores it (§9).

**`POST feeds/import/select`.** `SelectImportFeedsRequest` takes `urls` (`required|array|min:1`, `urls.*` `string`). Only URLs that exist in the parsed state are taken; anything else is ignored, so a client can't use this to submit arbitrary URLs. The request sets stage `running` and dispatches `ImportFeedSubscriptions($userId)` (ids only, STANDARDS 9.1). 404 if no state exists (same as Calendar).

**`GET feeds/import/state`** returns `stage` (`parsed` / `running` / `finished`), `total`, `processed`, and per entry `url`, `title` and `status` (`pending` / `subscribed` / `already_subscribed` / `invalid`), through an `ImportFeedsStateResource`. 404 if no state exists.

**Job `ImportFeedSubscriptions`** (`app/Api/Feeds/Jobs/`). It loads the state and calls `FeedService::createSubscription($user, $title, $url, null, null)` for each selected entry. It catches `FeedAlreadySubscribedException`, `FeedException` and (once §5.3 lands) `BlockedUrlException`, writes the status after each entry, and sets `finished` at the end. `$tries = 1`, so a retry doesn't redo half an import, and an explicit `$timeout` with the reason (STANDARDS 9.2). `createSubscription` already clears the user feed cache and dispatches `SyncFeed`.

**Code placement.** Put the parsing in `app/Api/Feeds/Services/OpmlImportService.php`, the same shape as `NoteImportService`. It holds parse, state save/load and the per-entry import, and gets `FeedService` injected (STANDARDS 4.2). `ImportFeeds` then calls the same parse method instead of its own `parseOpml`, so the seeding command also gains nested outlines and the `text` fallback. To share the title cleanup, `FeedService::plainTitle` becomes public, or the import passes the raw title and `createSubscription` cleans it. Either works; the second keeps the API the same.

**OpenAPI.** Add `@OA` blocks for the three actions (`operationId`s `feedImportStart`, `feedImportSelect`, `feedImportState`, STANDARDS 2.5), a `multipart/form-data` request body for the upload, a schema for the state resource, and 422 responses referencing `ValidationErrorResponse` (2.4). Rules, annotations and schema go in the same commit (2.6).

## 7. Tests

Pest, co-located in `app/Api/Feeds/Tests/`, with `FeedReader` mocked as `FeedApiTest.php:56` already does, `Queue::fake()` and `UploadedFile::fake()->createWithContent(...)`. OPML fixtures as strings or files:

- flat list (awesome-rss-feeds shape); nested Miniflux shape (one folder level); deeper NewsBlur nesting; FreshRSS with `text` only plus an `HTML+XPath` entry; Thunderbird `url` attribute; NewsBlur `xmlURL`; Feeder with `<feeder:settings>` in `<body>`;
- unescaped `&` in a URL survives; ISO-8859-1 declaration; UTF-8 BOM;
- **XXE fixture** (`<!DOCTYPE` with `SYSTEM "file://…"`) → 422 and no file content anywhere in the state;
- duplicate URLs collapse; URLs over 255 characters dropped; 501 entries → 422; 1 MB+ file → 422; second `start` while running → 409; throttle hit → 429;
- `select` ignores URLs that are not in the parsed state; the job records `subscribed` / `already_subscribed` / `invalid` correctly and dispatches `SyncFeed` only for new subscriptions;
- `app:feeds:import` still works through the shared parser (with `Http::fake`).

Note: SQLite in tests doesn't enforce `string(255)` lengths, so the URL-length guard needs an explicit test.

## 8. Effort estimate

| Part | Estimate |
|---|---|
| `OpmlImportService` (parse, state) + `ImportFeeds` switched to it | 0.5 d |
| Controller actions, two FormRequests, state resource, routes, limiter, OpenAPI | 0.5 d |
| `ImportFeedSubscriptions` job + typed exception | 0.25 d |
| Tests and fixtures (§7) | 0.75 d |
| **Import total** | **~2 d** |
| Separate prerequisite: `OutboundUrlGuard` in `FeedReader` (fetch + `og:image`, redirect handling, tests) | ~1 d |
| Optional: folder support (migration + field in resource + filter) | +1 d, more on the frontend |
| Optional follow-up: `GET feeds/export` (OPML export, flat or with folders) | ~0.5 d |

Frontend work (upload, preview with checkboxes, progress) lives in the separate solyto repo and isn't counted here.

**i18n impact:** none on the API side. The envelope messages are English and not translated (STANDARDS 12.10). There are no notifications, mails or bot replies. If a "your import has finished" notification were wanted later, it would follow 9.4 and need the translator agent.

## 9. Open decisions for humans

1. **Folders:** flatten them (recommended for v1; only the preview shows them), or add a `folder` (nullable string) column to `feed_subscriptions`? That would be a migration on the default MariaDB connection, not `pgsql`. It would also need the field in `FeedSubscriptionResource` and a frontend that groups by it. Old frontends ignore the new field (13.2).
2. **SSRF first?** Should the `FeedReader` guard (§5.3) land before the import ships? I recommend it. The import multiplies an existing hole.
3. **Invalid feeds:** skip them and report them as `invalid` (recommended, keeps `feeds` clean), or create them anyway with `last_error` set so a later retry can pick them up (option B, needs a way to hide them from `available`/`search`)?
4. **Limits:** 1 MB per file, 500 entries per import, 5 imports per hour. Are these numbers OK? And should there also be a total per-user subscription cap, which is still open from the bot-traffic report?
5. **Preview step:** keep `select` (recommended, matches Calendar/Contacts), or import everything in one call?
6. **Duplicate-feed race:** add a unique index on `feeds.url` (migration ⚑, existing duplicates would need cleaning first), or use a cache lock in the import path?
7. **OPML export:** do it as a follow-up job? It is cheap, and users who import usually expect to be able to export again.

## 10. Sources

- [S1] OPML 2.0 specification, http://opml.org/spec2.opml (sections "What is an outline?", "Subscription lists", "category").
- [S2] Miniflux: `internal/reader/opml/opml.go` (`GetTitle`, `IsSubscription`), `parser.go` (`getSubscriptionsFromOutlines`), `serializer.go`. https://github.com/miniflux/v2/tree/main/internal/reader/opml
- [S3] FreshRSS: `app/views/helpers/export/opml.phtml` (export attributes), `app/Services/ImportService.php` (`loadFromOutline`, `text` → `title` precedence, `category` attribute, `max_feeds`), `app/Services/ExportService.php` (`TYPE_*` constants), `config.default.php` (`max_feeds` 131072). https://github.com/FreshRSS/FreshRSS (branch `edge`)
- [S4] NewsBlur: `apps/feed_import/models.py` (`OPMLImporter.process_outline`, `xmlUrl`/`xmlURL`, nested folders). https://github.com/samuelclay/NewsBlur
- [S5] Feeder: `app/src/main/java/com/nononsenseapps/feeder/model/opml/OpmlWriter.kt`. https://github.com/spacecowboy/Feeder
- [S6] Thunderbird: `mailnews/extensions/newsblog/feed-subscriptions.js` (`importOPMLOutlines`, `type="rss"` required, `xmlUrl || url`). https://github.com/mozilla/releases-comm-central
- [S7] NetNewsWire's RSParser: `Sources/ObjC/RSOPMLParser.m`. https://github.com/Ranchero-Software/RSParser
- [S8] Feedly docs: "How can I export my sources and feeds (through OPML)?" https://docs.feedly.com/article/52-how-can-i-export-my-sources-and-feeds-through-opml
- [S9] Inoreader blog: "OPML subscriptions". https://www.inoreader.com/blog/2014/05/opml-subscriptions.html
- SimplePie 1.9.0 defaults: `src/SimplePie.php:452,552,566`, `src/File.php:89`. https://github.com/simplepie/simplepie/tree/1.9.0
- libxml probes: run locally against PHP 8.4.27 / libxml 2.9.14 (§5.1). The scripts were not kept.
