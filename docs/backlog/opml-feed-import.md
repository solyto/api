# Backlog: import feeds from an OPML file (api)

created: 2026-11-13

## What

The api side of an OPML import. A user uploads the OPML export from another
feed reader. The api parses it and returns every feed it found. The user
ticks the ones they want (in the app), and the api subscribes them in one
queued job. The job fetches the feeds 25 at a time, in parallel, so a normal
file finishes in seconds. The app polls the progress and shows per feed what
worked and what did not. The UI is a separate app entry
(`app/docs/backlog/opml-feed-import.md`).

Background and research: `docs/reports/opml-feed-import-2026-11-13.md`. It is
still correct on the OPML format (§3), XML safety (§5.1), and the existing code
(§2). It is **out of date** on the job shape (§4.3, §6: one serial job and
"fan out as fallback"). The decisions below replace that.

### Endpoints

All three go inside the existing `feeds` prefix in `routes/api.php`
(`:385-399`), under `auth:sanctum`. They come before the `subscriptions` block,
as a `Route::prefix('import')` sub-group (rule 1.4).

```
POST feeds/import          multipart: file          throttle:feeds-import
POST feeds/import/select   { "urls": ["https://…", …] }
GET  feeds/import/state
```

**`POST feeds/import`.** It uploads and parses the file and stores the state.
It returns the state (stage `parsed`) with status 200.
- `ImportFeedsRequest`: `file` → `required|file|max:1024|extensions:opml,xml`
  (1 MB).
- 409 if this user has an import with stage `running`. If there is an old
  state with stage `parsed` or `finished`, the new upload replaces it.
- 422 (typed exception, see below) if the file is unparseable, contains a
  DTD, holds no feeds, or holds more than **500** feeds after deduplication.

**`POST feeds/import/select`.** It takes the ticked URLs, sets stage
`running`, dispatches the job and returns the state.
- `SelectImportFeedsRequest`: `urls` → `required|array|min:1|max:500`,
  `urls.*` → `required|string`.
- Only URLs that exist in the parsed state and are not `already_subscribed`
  are used. Anything else is silently dropped, so a client can't use this
  endpoint to make the server fetch arbitrary URLs. If nothing is left, 422.
- 404 if no state exists (same as Calendar). 409 if the stage is not
  `parsed`.

**`GET feeds/import/state`.** It returns the state. 404 if none exists.

### State (the contract the app builds on)

```json
{
  "stage": "parsed",
  "total": 2,
  "processed": 0,
  "feeds": [
    { "url": "https://example.com/feed.xml", "title": "Example", "already_subscribed": false, "status": null },
    { "url": "https://other.org/rss", "title": "Other", "already_subscribed": true, "status": null }
  ]
}
```

- `stage`: `parsed` → `running` → `finished`.
- `feeds`: in `parsed`, every feed from the file, in file order. After
  `select`, only the selected feeds.
- `status`: `null` in `parsed`. After `select` it is `pending`, then one of:
  - `subscribed`: a new subscription was created.
  - `already_subscribed`: the user got the feed between preview and import,
    for example by a manual subscribe.
  - `unreachable`: connection error, timeout, or a non-2xx response.
  - `not_a_feed`: it answered with 2xx, but SimplePie found no items.
- `total` = `count(feeds)`, `processed` = feeds whose status is neither `null`
  nor `pending`.
- No per-status counts. The app counts them itself (one source of truth).

Field names are final. The app entry relies on them. If you change one, change
the app entry in the same go.

The state is per user and lives for 60 minutes. It is stored through
`UserCacheService` (`store`/`get`/`replace`/`forget`). The key and TTL are
`private const`s on the new service (rule 8.1). **Not** the `Cache::` facade
the Calendar import uses (`app/Dav/Services/CalendarImport.php:212`). That is
a violation of 8.1 and must not be copied.

### Parsing (new `app/Api/Feeds/Services/OpmlImportService.php`)

Follows the report §3 "Takeaways" and §5.1. In order:

1. Reject the file if it contains `<!DOCTYPE` or `<!ENTITY` (OPML never uses a
   DTD). This guards against XXE regardless of flags.
2. Escape stray `&`, with the same regex as `ImportFeeds.php:104`. Without it,
   libxml's recovery silently corrupts `?a=1&b=2` URLs.
3. `simplexml_load_string` with
   `LIBXML_RECOVER | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET`. Never
   `LIBXML_NOENT`, `LIBXML_DTDLOAD` or `LIBXML_PARSEHUGE`.
4. Walk `body` **recursively**. An `<outline>` with a feed URL is a feed.
   Recurse into every other outline (folders). Ignore non-outline elements in
   `body` (Feeder's `<feeder:settings>`) and vendor attributes (`miniflux:*`,
   `frss:*`).
5. The feed URL is `xmlUrl`, else `xmlURL` (NewsBlur), else `url`
   (Thunderbird). `type="rss"` is not required.
6. Skip FreshRSS outlines whose `type` is a scraper type (`HTML+XPath`,
   `XML+XPath`, `JSON+DotNotation`, `JSONFeed`). Their URL is a web page, not
   a feed.
7. Keep only `http`/`https` URLs of at most 255 characters
   (`feeds.url` is `string(255)`). Others are dropped silently.
8. The title is `title`, else `text`, else the URL. Strip tags and decode
   entities the way `FeedService::plainTitle` does (`:353`, currently
   private). Truncate to 255 characters. Reuse `plainTitle` instead of
   writing a second one. Make it reachable (public, or move it) and say why
   in the implementation notes.
9. Deduplicate by URL. The first occurrence wins.
10. Mark `already_subscribed` with one query over the user's subscriptions
    joined to `feeds.url`, not one query per feed.
11. More than 500 left → 422. Zero left → 422.

Folders are flattened and not stored. The folder name is not part of the
state either.

**Do not change `app:feeds:import` (`ImportFeeds.php`)** (owner decision,
2026-11-13). It keeps its own `parseOpml`. Copying the one escape regex is
accepted.

### The job (`app/Api/Feeds/Jobs/ImportFeedSubscriptions.php`)

`ImportFeedSubscriptions(string $userId)`: ids only (rule 9.1).

- `$tries = 1`: a retry would redo a half-finished import. `$timeout = 300`:
  it must stay under the queue's `retry_after` of 360 s
  (`config/queue.php:44,54`). Rule 9.2 wants the reason in the code. One line
  is enough.
- Load the state. Return if it is missing or not `running`.
- Split the selected feeds:
  - **URL already in `feeds`:** subscribe directly, no fetch (the same path
    `createSubscription` takes today).
  - **New URL:** fetch in chunks of **25** with `Http::pool`. Each request
    gets a **5 s** timeout. Redirects are followed (Laravel's default).
    Send the same User-Agent SimplePie sends today. Some sites block
    Guzzle's default UA, and a feed must not be `unreachable` here but work
    as a single subscribe.
- Per response:
  - Exception (connection, timeout) or non-2xx → `unreachable`.
  - 2xx → parse the body through `FeedReader` (below). No items →
    `not_a_feed`. Otherwise create the `Feed` and the subscription.
- `FeedAlreadySubscribedException` → `already_subscribed`.
- After each chunk, write the state (`processed`, statuses), so the app sees
  progress. At the end, set `finished`.
- Worst case: 500 new feeds, all dead = 20 chunks × 5 s = 100 s, well under
  the timeout. A normal list of 50–200 mostly-live feeds takes a few seconds.
- No `Log::info` progress lines (rule 4.9). The Calendar job's
  `Log::info('Importing …')` is a violation, not a precedent.
- `failed(Throwable $e)`: load the state, set every feed still `pending` to
  `unreachable`, set `finished`, save. Without it a crashed or timed-out job
  leaves the stage at `running`: every upload gets 409 until the state
  expires, and the app polls forever. No new stage or status, so the contract
  stays as it is. Calling feeds the job never reached "unreachable" is a
  small inaccuracy, accepted for a rare failure.

### Changes to existing code

- **`FeedReader`** gets a method that parses already-downloaded content
  through SimplePie's `set_raw_data` and returns the same shape as
  `getFeedData` (`['title' => …, 'items' => …]` or `false`). `getFeedData`
  and `getItems` stay unchanged.
  Consequence, accepted: no SimplePie autodiscovery on the import path. An
  `xmlUrl` that points to an HTML page becomes `not_a_feed`. OPML exports
  contain direct feed URLs, so this is rare.
- **`FeedService`**: today `createSubscription` (`:83-120`) fetches, creates
  the `Feed`, creates the subscription, forgets the user feed cache and
  dispatches `SyncFeed`. The job needs the same steps with feed data that was
  already fetched. Split it so both paths share one private method for
  "create feed if needed, create subscription, forget, dispatch". Don't copy
  the block. `createSubscription`'s signature, behaviour and exceptions stay
  exactly as they are. The single subscribe endpoint and its tests must not
  change.
- Forgetting `user_feeds` once per new subscription is fine (it is one cache
  delete). Forgetting once at the end of the job is also fine. Pick one and
  keep it simple.

### Controller, requests, exceptions, resource

- New `app/Api/Feeds/Controllers/FeedImportController.php` with three actions
  (`start`, `select`, `state`), like `Calendars/Controllers/ImportController.php`
  but without its flaws. Each action is: FormRequest (or plain `Request` for
  `state`), one service call, `ApiResponse::success(new FeedImportStateResource(…))`
  (rule 1.1). No queries or pre-checks in the controller. "Already running" and
  "no state" are decided in the service and thrown (rules 7.2, 7.3).
- Typed exceptions in `app/Api/Feeds/Exceptions/` (where
  `FeedAlreadySubscribedException` lives), mapped once in the controller to
  422 / 409 / 404. Pick the smallest set that expresses the three statuses.
  Messages are English sentences ending in a period, with no user input
  echoed (rule 7.4).
- `FeedImportStateResource` with `@OA\Schema` (rule 6.2). Its `toArray()`
  returns one `return [ … ]` literal so `ResourceSchemaDriftTest` can read it.
  If the state is a DTO, add `@mixin` (rule 10.2). A DTO in
  `app/Api/Feeds/DTOs/` is fine (module DTO folders exist,
  `app/Api/Libraries/DTOs/`). It must be serialisable into the cache.
- Named limiter `feeds-import` in `app/Shared/Providers/AppServiceProvider.php` next to
  `account-deletion` (`:81`): `Limit::perHour(20)->by($request->user()->id)`.
  Only on `POST feeds/import`. Failed uploads (422) count too, so the limit
  is set high enough that picking the wrong file a few times doesn't lock a
  user out. The upload is only a 1 MB parse. The expensive part, fetching,
  is already gated by `select` and the 409.

### OpenAPI (section 2, one commit with the rules, rule 2.6)

- Relative paths (`/feeds/import`, …), `operationId`s `feedImportStart`,
  `feedImportSelect`, `feedImportState` (unique, rule 2.5).
- `multipart/form-data` request body for the upload. JSON body for select.
- Success responses with `allOf SuccessResponse` + `data` →
  `FeedImportState` schema (rule 2.3).
- FormRequest 422s use `ValidationErrorResponse`. Own 422s (bad file, too
  many feeds) use `ErrorResponse` (rule 2.4). Also document 404, 409, 429.
- `OpenApiSpecTest` must stay green with no new drift.

## Why

Users coming from another reader (Feedly, Inoreader, Miniflux, FreshRSS,
NewsBlur, Thunderbird, NetNewsWire, …) have tens to hundreds of feeds and
expect to bring them along in one step. Today they have to add every feed by
hand. The owner's bar: "even with 500 feeds, as a user I'd expect this to take
a few seconds", plus visible progress and a clear list of what worked and
what didn't.

## Out of scope

Owner decisions, 2026-11-13:

- **SSRF guard.** `FeedReader` and this import fetch user-chosen URLs with no
  host check (report §5.3). Deliberately deferred. Parallel fetching means up
  to 25 such requests at once. Do not add a guard in this job, and do not
  wire in `OutboundUrlGuard`.
- **Folders.** Flattened. No column, no migration.
- **Duplicate-feed race.** `feeds.url` has no unique index. Don't add a lock
  or an index.
- **OPML export.**
- **`app:feeds:import`** stays untouched.
- **A cap on subscriptions per user.**
- **Lower timeouts or autodiscovery changes** for single subscribe or the
  hourly sync.
- No notification when the import finishes. The app polls.
- No migration of any kind.

## Notes

- **Tests** (`app/Api/Feeds/Tests/`, Pest, `docs/TESTING.md`). OPML fixtures
  as strings or in `tests/Fixtures/`. Use `Http::fake()` for every fetch. No
  live calls. Use `Queue::fake()` where only dispatch matters, otherwise run
  the job and assert the rows and the state (TESTING: assert the effect).
  - **Parser, through `app(OpmlImportService::class)`:**
    - shapes: flat list (awesome-rss-feeds), Miniflux one-level folders,
      NewsBlur deep nesting with `xmlURL`, FreshRSS `text`-only titles plus an
      `HTML+XPath` entry (skipped), Thunderbird `url` attribute, Feeder's
      `<feeder:settings>` in `body`;
    - content: an unescaped `&` survives intact, an ISO-8859-1 declaration, a
      UTF-8 BOM, an entity-encoded HTML title becomes plain text;
    - filtering: duplicates collapse, non-http URLs and URLs over 255
      characters are dropped.
  - **Upload endpoint:**
    - the XXE fixture (`<!DOCTYPE` with `SYSTEM "file://…"`) → 422, and no
      file content anywhere in the state;
    - 501 feeds → 422; empty → 422; over 1 MB → 422; wrong extension → 422;
    - a second upload while `running` → 409; the 21st upload in an hour →
      429;
    - `already_subscribed` is set correctly.
  - **Select endpoint:** a URL not in the state is dropped. Only unknown URLs
    → 422. No state → 404. Not `parsed` → 409.
  - **Job, with `Http::fake`:**
    - every outcome lands with the right status: `subscribed`,
      `already_subscribed`, `unreachable` (connection exception, timeout,
      404, 500), `not_a_feed` (an HTML 200);
    - an existing `Feed` row is subscribed without any HTTP call
      (`Http::assertNothingSent` for it);
    - `SyncFeed` is dispatched only for new subscriptions;
    - `processed` and `stage` end correct;
    - an exception partway through: after `failed()`, the stage is
      `finished`, no feed is left `pending`, and the leftover feeds are
      `unreachable`.
  - **Tenant isolation:** user B gets 404 on `state` and `select` while user A
    has an import, and never sees A's feeds.
  - The existing single-subscribe tests stay green unchanged.
  - SQLite does not enforce `string(255)`, so the 255-character filter needs
    its own test (report §7).
- **No client contract test.** These are new endpoints, so the change is
  additive (rule 13.2).
- **i18n:** none on the api side. Envelope messages are English (12.10).
- **Deploy order:** this goes out before the app entry is released (rule 13.7).
- **Done means** `composer test` and `composer analyse` both exit 0 in the
  worktree, with the full suite, not only the feed tests (`docs/AGENTS.md`).
- Don't touch the Calendar/Contacts import code. Its violations (Cache
  facade, `Log::info`, no `$timeout`) are noted here only so they aren't
  copied.
