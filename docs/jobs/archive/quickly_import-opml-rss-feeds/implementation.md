# Implementation: import opml rss feeds

id: quickly
developer: developer
date: 2026-11-13

## Summary

Report-only job. The deliverable is `docs/reports/opml-feed-import-2026-11-13.md`.
It finds that OPML import is feasible and mostly built from parts that already
exist. It recommends a three-step import (upload/preview → select → poll state)
using a queued job, following the Calendar/Contacts import pattern. It also
recommends wiring `OutboundUrlGuard` into `FeedReader` first, to close the
existing SSRF gap. No application code, routes, config or migrations changed.
`composer test` and `composer analyse` both exit 0.

## Changes

TASK-1: Report §2 covers the current state: the shared `feeds` and per-user `feed_subscriptions` tables, `createSubscription`'s synchronous fetch, the gaps in `ImportFeeds::parseOpml` (top-level outlines only, no `text`/`xmlURL`/`url` fallbacks, folder outlines not filtered, untyped exception), and the missing import endpoint.
TASK-2: Report §3 covers OPML 2.0 and the export/import behaviour of Miniflux, FreshRSS, NewsBlur, Feeder, Thunderbird, NetNewsWire, Feedly and Inoreader, plus parser takeaways. Sources are cited in §10.
TASK-3: Report §4 compares synchronous import, create-now/validate-later, and upload → select → queued job → poll. It also covers the preview step, per-feed validation through `createSubscription`, job shape and the duplicate-feed race.
TASK-4: Report §5 covers XXE and entity expansion (probed on PHP 8.4 / libxml 2.9.14), size and entry caps, SSRF (`FeedReader` has no guard and `OutboundUrlGuard` is unused by feed code), rate limiting, and the extra load on the shared table and hourly sync.
TASK-5: Wrote `docs/reports/opml-feed-import-2026-11-13.md`: summary, current state with refs, options, API contract sketch, OpenAPI notes, tests, effort (~2 d plus ~1 d for the SSRF guard), i18n (none), open decisions, and OPML export as a follow-up.

## Known issues / follow-ups

- Human decisions are listed in report §9: folders, SSRF guard first, handling of invalid feeds, limits, preview step, duplicate-feed race, and OPML export.
- SSRF guard for `FeedReader` (single subscribe, `feeds/test`, sync, `og:image`) is a separate job. It already affects single subscriptions today.
