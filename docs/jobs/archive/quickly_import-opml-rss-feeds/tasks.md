# Tasks: import opml rss feeds

id: quickly
analyst: analyst
date: 2026-11-13

This is a report-only job (`type: report`). The deliverable is one markdown
report in `docs/reports/`. No code, routes, migrations, config or
dependencies change.

## Starting points found during analysis

- `app/Api/Feeds/Commands/ImportFeeds.php`: an artisan command
  (`app:feeds:import`) that already parses OPML (`parseOpml()`, using
  simplexml with `LIBXML_RECOVER` and an `&` fix-up). It only reads
  top-level `body > outline` elements, so nested folder outlines are not
  covered. It seeds the global `feeds` table through
  `FeedService::addFeed()`. It does not create user subscriptions.
- `app/Api/Feeds/Services/FeedService.php::createSubscription()`: fetches
  the feed synchronously via `FeedReader` when the feed is new, throws
  `FeedAlreadySubscribedException` or `FeedException`, clears the user feed
  cache and dispatches `SyncFeed`. If an OPML import calls this once per
  outline inside a request, it makes N blocking HTTP fetches.
- `app/Api/Feeds/Controllers/FeedController.php` and `routes/api.php`
  (around line 385, the `feeds` prefix) have no import endpoint today.
- Existing import patterns to compare against:
  `app/Api/Notes/Services/NoteImportService.php` (file upload),
  `Calendars/Contacts ImportController` (`startImport` / `select` / `state`
  flow), and `Libraries ... import/{service}`.
- No folder/category concept exists on `FeedSubscription`. OPML folders
  would be dropped or flattened unless the model changes.

## Task breakdown

TASK-1: Document how feeds and subscriptions work today, plus the existing OPML parsing in `ImportFeeds`, and note what can be reused and where it falls short (nested outlines, `xmlUrl`/`text`/`title` fallbacks, global seeding vs. per-user subscription).
     files: app/Api/Feeds/Commands/ImportFeeds.php, app/Api/Feeds/Services/FeedService.php, app/Api/Feeds/Services/FeedReader.php, app/Api/Feeds/Models/*.php, app/Api/Feeds/Controllers/FeedController.php, app/Api/Feeds/Requests/StoreFeedSubscriptionRequest.php, routes/api.php (read only)
     depends: none
     risk: low — reading only.

TASK-2: Research the OPML 1.0/2.0 format and real-world exports (Feedly, Inoreader, NewsBlur, FreshRSS, Miniflux, Thunderbird, Feeder, NetNewsWire) for nesting, attribute variants, encoding quirks and typical file sizes.
     files: none (web research; cite sources)
     depends: none
     risk: low — research only, but external sources must be cited, not assumed.

TASK-3: Compare the import pattern options against the patterns already in the codebase: synchronous request vs. upload + queued job + state polling (like the Calendar/Contacts import), preview/select step vs. import-all, and per-feed validation (fetch now vs. create the subscription and let `SyncFeed` validate).
     files: app/Api/Calendars/Controllers/ImportController.php, app/Api/Contacts/Controllers/ImportController.php, app/Api/Notes/Services/NoteImportService.php, app/Api/Feeds/Jobs/SyncFeed.php (read only)
     depends: TASK-1
     risk: low — reading only. The recommendation must stick to existing patterns (no new abstractions).

TASK-4: Cover security and abuse: XML parsing safety (XXE/entity expansion with simplexml and libxml under PHP 8.4), upload size and outline-count limits, SSRF from user-supplied `xmlUrl` (and whether `FeedReader` already guards this for single subscriptions), rate limiting, and the effect on the shared `feeds` table and the hourly `SyncFeeds` load.
     files: app/Api/Feeds/Services/FeedReader.php, app/Api/Feeds/Jobs/SyncFeeds.php, routes/console.php, bootstrap/app.php (read only)
     depends: TASK-1
     risk: medium — the SSRF and XML findings drive the recommendation, so the report must back them with code references, not assumptions.

TASK-5: Write the report to `docs/reports/opml-feed-import-2026-11-13.md`, following the style of the existing reports (summary, current state with `path:line` refs, options, recommended approach with an endpoint/request/response sketch, OpenAPI and test notes, i18n impact (likely none, since the API returns English messages), effort estimate, open questions). Optionally mention OPML export as a follow-up.
     files: docs/reports/opml-feed-import-2026-11-13.md (new)
     depends: TASK-1, TASK-2, TASK-3, TASK-4
     risk: low — documentation only, no runtime effect.

## Unclear / open questions for the report (not blockers)

- The brief's Why / Out of scope sections are empty. I assume import only;
  OPML export is a possible follow-up, not in scope.
- Folder/category support: should the report propose a schema change
  (migration on the default MariaDB connection, not `pgsql`), or recommend
  flattening for now? The report should present both options and leave the
  decision to a human.
- Frontend work lives in a separate repo. The report should describe the API
  contract only.
- Should imported feeds that fail validation be skipped, or created anyway
  with `last_error` set? The report should list this as a decision point.
