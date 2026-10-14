# Implementation: Analyze Bot Traffic

id: sheet
developer: @developer
date: 2026-10-14

## Summary

This job produces a report only: `docs/reports/bot-traffic-detection-2026-10-14.md`, in the same format as the ntfy report. No application code, config, migrations, routes or lang files were changed. The diff only touches `docs/`.

Main findings:

- Only the public auth routes are throttled. The whole `auth:sanctum` group, and `/dav`, have no request ceiling.
- Feed subscriptions are the strongest "crawl through us" vector. Any URL gets re-fetched every hour, plus one page fetch per new item, with no cap on subscriptions per user.
- Pulse records per-user request counts, but there's no `viewPulse` gate, so the dashboard can't be opened in production.
- DAV Basic auth doesn't check `email_verified_at` and has no throttle ⚑.

Recommendation (not decided): option A (observe) plus option B (per-user and per-endpoint limits). Neither needs a new package or a migration.

## Changes

TASK-1: I read and listed the rate limiters, verification policy, client IP and `TRUSTED_PROXIES` handling, Pulse recorders, token, session, AI-usage and feed metadata, logging channels and DAV auth, with file:line references. This is report §1. Read-only.

TASK-2: I listed 14 endpoints where a user makes the server do outbound or expensive work (feeds, link title, cover download, Goodreads, third-party search and import, trailers, AI recommender, DAV import, weather, export, uploads, DAV), each with its cost driver and current limits. This is report §2. Read-only. Side finding: none of these user-URL fetchers has an SSRF guard.

TASK-3: I defined bot signals per threat ((a) fetcher abuse, (c) high-volume clients, (b) scripted registration, plus credential stuffing), each with the data it needs, whether that data exists today, and the false-positive risk. This is report §3–§4, with OWASP OAT and Laravel docs cited and fetched on 2026-10-14.

TASK-4: I set out options A–D with effort, risk, privacy impact and the self-hosted default, plus a comparison table. DAV-touching items are flagged ⚑. This is report §5–§7.

TASK-5: I wrote `docs/reports/bot-traffic-detection-2026-10-14.md` (new): Summary, Method, numbered sections, proposed follow-up jobs, and 11 open questions, including the four from tasks.md.

TASK-6 (optional): skipped. `docs/STANDARDS_WORKFLOW.md` doesn't require it, and backlog entries in this repo record decisions that have already been made (see `docs/backlog/ntfy-notifications.md`). The maintainer still has to answer the open questions first. `docs/backlog/analyze-bot-traffic.md` is unchanged.

Verification: `composer test` exits 0 (758 warnings, all from the missing `.env` file, 2167 assertions). `composer analyse` exits 0 ("No errors").

## Known issues / follow-ups

- Environment: `vendor/` was missing. I ran `composer install --no-scripts` (locked dependencies only, no new packages). The first two attempts hit GitHub HTTP 429 and the third succeeded. I also created the git-ignored `storage/framework/*`, `storage/logs`, `storage/app/public` and `storage/app/private` directories. Without `storage/app/public`, two `ExportTest` cases fail with `fopen … No such file or directory`.
- `.env.testing` shows as modified in `git status`, but git can't hash it (it's a character-device mount). I didn't change it and didn't commit it.
- Proposed follow-up jobs are listed in report §7. They include checking `TRUSTED_PROXIES` in production (deployment repo), the observe tooling, per-user limiters, the `auth-login` key fix, ⚑ DAV auth hardening, and an SSRF guard for user-supplied URLs.
- Unverified side note, not tested: `FeedService::getTestFeedItems` (`FeedService.php:67-74`) passes the result of `getFeedItemsFromRss` to `array_map`. When that result is `false` (a URL that isn't a feed), PHP 8 throws a `TypeError`, so `feeds/test` probably returns a 500 instead of the intended 422.
