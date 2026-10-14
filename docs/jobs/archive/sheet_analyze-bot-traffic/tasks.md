# Tasks: Analyze Bot Traffic

id: sheet
analyst: @analyst
date: 2026-10-14

<!-- Produced by @analyst from brief.md. -->

## Scope reading

The brief asks for a **strategy to identify bot traffic** and a report in
`docs/reports/`. This job produces only a report. **No application code,
config, migrations, routes or lang files change.** Any detection or limiting
code is a follow-up job, the same way the ntfy report was handled
(`docs/reports/ntfy-notifications-2026-10-14.md`). Use that report's shape:
header with date, branch, scope and `read: false`, then Summary, Method,
numbered sections and open questions.

Suggested file: `docs/reports/bot-traffic-detection-2026-10-14.md`.

### What I found while scoping (starting points, not conclusions)

- **Only the public auth routes are throttled.** There are four named
  limiters in `app/Shared/Providers/AppServiceProvider.php:44-58`
  (`auth-register` is 10/hour per IP). The whole `auth:sanctum` group in
  `routes/api.php:80-412` has no `throttle` middleware, so a registered
  account has no request ceiling at all.
- **Email verification only blocks login when `auth.policy.confirmation`
  is on** (`app/Api/Users/Requests/LoginRequest.php:31`). Registration
  (`AuthService::register`) records no IP or user agent.
- **Endpoints where a user makes the server fetch URLs or call paid or
  limited APIs.** These are the most likely "crawl through us" vectors:
  - `UrlCrawlerService::fetchHtml`: any URL, used by library links.
  - `POST feeds/test` and feed subscriptions (`FeedReader`, hourly
    `ImportFeeds`).
  - `LibraryCoverService` URL download.
  - `GoodreadsService`.
  - `libraries/*/search/{service}/{query}` and `import/{service}`
    (TMDB, Spotify, Discogs, Hardcover, Steam, BGG, Chefkoch).
  - AI via `AiService` and `dashboard/quick-add`.
  - Calendar and contact import (remote DAV fetch).
- **What we can already observe:**
  - Pulse is configured with `UserRequests`, `SlowRequests` and
    `SlowOutgoingRequests` recorders, kept for 7 days (`config/pulse.php`).
  - `personal_access_tokens.last_used_at` exists.
  - The `sessions` table has `ip_address` and `user_agent`, but the API is
    token-based, so those columns probably aren't filled for API traffic
    (**unverified**).
  - Telescope is local-only (`AppServiceProvider.php:23`).
- **DAV Basic auth (`app/Dav/Auth/LaravelAuthBackend.php`) has no throttle
  or lockout.** It's a credential-stuffing and bot surface. Changing it is ⚑
  DAV-sensitive, so the report may only describe it.

### Unclear: report these as open questions, don't guess

1. **Which threat does the brief mean?** "Users that register to run their
   AI crawling service through it" could mean (a) accounts that use solyto's
   outbound fetchers as a crawling proxy, (b) scripted mass registration,
   or (c) high-volume automated API clients, including AI agents driving
   the API. The report should cover all three but say which one it ranks
   first and why.
2. **Edge and infrastructure aren't visible from this repo.** The reverse
   proxy, any CDN or WAF (Cloudflare and similar) and access-log retention
   are in the separate `solyto/deployment` repo. The report must mark
   anything that depends on them as **unverified**.
3. **No production traffic data is available in the sandbox.** The report
   can't quantify current bot traffic. It can only propose signals and how
   to collect them.
4. **Self-hosted instances.** Should detection be on by default, or limited
   to solyto.app? Privacy expectations matter here: storing IPs and user
   agents is personal data under GDPR. This is a maintainer decision.

## Task breakdown

TASK-1: List the current controls and observability around traffic (rate limiters, verification policy, Pulse recorders, token metadata, trusted proxies, DAV auth), citing file:line.
  files (read-only): app/Shared/Providers/AppServiceProvider.php, routes/api.php, routes/web.php, bootstrap/app.php, config/pulse.php, config/sanctum.php, config/auth.php, config/logging.php, app/Api/Users/Requests/LoginRequest.php, app/Api/Users/Services/AuthService.php, app/Dav/Auth/LaravelAuthBackend.php, database/migrations/*users*, *personal_access_tokens*
  depends: none
  risk: low. It only reads code, but it has to separate "configured" from "verified in production".

TASK-2: List every endpoint where a user can make the server do expensive or outbound work (URL fetches, third-party APIs, AI, imports, exports, DAV sync), with its cost driver and current limits.
  files (read-only): app/Shared/Services/UrlCrawlerService.php, app/Api/Feeds/Services/FeedReader.php, app/Api/Feeds/Commands/ImportFeeds.php, app/Api/Libraries/Services/LibraryCoverService.php, app/Api/Libraries/Services/External/*.php, app/Api/Libraries/Services/AiService.php, app/Api/Dashboard/**, app/Dav/Services/{CalendarImport,ContactImport}.php, app/Api/Export/**, routes/api.php
  depends: none
  risk: low. It's read-only, but the list must be complete, because a missed fetcher is a missed crawl vector.

TASK-3: Define candidate bot signals for each threat (registration patterns, request rate and cadence per token or user, endpoint mix skewed to fetchers, unusual hours, user agent, many accounts per IP or email domain, unverified accounts that stay active), and give for each one what data it needs and whether that data exists today.
  files (read-only): outputs of TASK-1 and TASK-2. External sources (OWASP Automated Threats, Laravel rate-limiting and Pulse docs) must be cited with fetch dates.
  depends: TASK-1, TASK-2
  risk: medium. Signals that rely on IPs or user agents raise GDPR and retention questions, and false positives could hit legitimate power users or the frontend's polling.

TASK-4: Set out tiered strategy options (A: observe only, with Pulse plus a logging channel and an artisan report command; B: per-user and per-endpoint rate limiting on authenticated and outbound routes; C: registration hardening such as mandatory verification, captcha or disposable-email checks; D: edge or WAF measures), each with effort, risk, privacy impact and the self-hosted default. Give a recommendation, but don't decide.
  files (read-only): none beyond the earlier tasks
  depends: TASK-3
  risk: medium. Options C and D may need new packages or infrastructure, which the report can only flag. ⚑ Any option that touches the DAV route or the `pgsql` layout must be flagged.

TASK-5: Write the report to `docs/reports/bot-traffic-detection-2026-10-14.md` in the ntfy-report format (Summary, Method, current state, attack surface, signals, options, recommended follow-up jobs, open questions including the four above), with file:line references and **unverified** marks.
  files: docs/reports/bot-traffic-detection-2026-10-14.md (new)
  depends: TASK-1, TASK-2, TASK-3, TASK-4
  risk: low. It's a documentation-only change, but the developer must not change any app code, config or migrations.

TASK-6 (optional, confirm with maintainer): Fill in the empty backlog entry `docs/backlog/analyze-bot-traffic.md` with a pointer to the report and the proposed follow-up jobs.
  files: docs/backlog/analyze-bot-traffic.md
  depends: TASK-5
  risk: low. It's documentation only. It's optional because the brief doesn't ask for it, so skip it unless the backlog workflow (docs/STANDARDS_WORKFLOW.md) requires it.

## Done criteria

- The report exists in `docs/reports/` and the diff contains no files
  outside `docs/`.
- `composer test` and `composer analyse` still exit 0. Nothing should
  change them, but the project rules say they must be run.
- No secrets or `.env` values are quoted in the report.
