# Report: identifying bot traffic

date: 2026-10-14
branch: `feature/sheet_analyze-bot-traffic` (job `sheet`), tree at `e790cf8`
scope: Develop a strategy to identify bot traffic, e.g. accounts that register to run an AI crawling service through solyto, before it causes bad performance. Written for the maintainer, who makes the product decisions. This report only describes the current state, signals and options. No application code, config, migrations, routes or lang files were changed. Detection or limiting code goes to follow-up jobs.
read: true

## Summary

**Today, a registered and verified account has no request ceiling at all.** The only rate limiters are the four on the public auth routes (`AppServiceProvider.php:44-58`, `routes/api.php:70-76`). The whole `auth:sanctum` group (`routes/api.php:80-412`) has no `throttle` middleware. The one per-user limit anywhere in the API is the "one export per 24 hours" check in `ExportController.php:29-38`.

**The most likely "crawl through us" vector is feeds, not link previews.** Most outbound fetchers only return a title or a few parsed fields. Feed subscriptions are different:

- `POST feeds/subscriptions` accepts any URL. The server then re-fetches it **every hour forever** (`routes/console.php:8`).
- For every new item, the server also fetches the item's page to look for an `og:image` (`FeedReader.php:85-114`).
- Nothing limits how many feeds one user can subscribe to (§2).

So one account can make solyto crawl thousands of pages an hour from solyto's IP address. Other notable vectors:

- Goodreads import and cover downloads fetch any URL.
- Library search proxies to third-party APIs whose quotas all users share.
- The AI recommender spends the instance's AI key with no cap.

**What we can observe today is thin, and nobody can look at it in production.** Pulse records per-user request counts for 7 days (`config/pulse.php:63,227-234`). But there's no `viewPulse` gate, so the dashboard only works in the `local` environment (Laravel Pulse docs). Registration stores no IP address or user agent. The `sessions` table isn't used, because `SESSION_DRIVER` defaults to `redis` (`config/session.php:21`). Useful per-user data does already exist, though: `ai_usage`, `feeds.created_by`, `personal_access_tokens.last_used_at`, and `created_at` on every library row (§1.4).

**Threat ranking (§3):** I rank **(a) authenticated abuse of outbound and expensive endpoints** first, with **(c) high-volume automated API clients** close behind. Both come from the same root cause: no per-user ceiling. **(b) Scripted mass registration** comes third, because registration is already throttled per IP and login needs a verified email by default. One caveat: **DAV Basic auth doesn't check `email_verified_at`** (`LaravelAuthBackend.php:11-22`) ⚑.

**Recommendation (not decided):**

1. **Option A**, observe only, first: define the `viewPulse` gate, add a dedicated log channel, and add an artisan report command that ranks users by the signals in §4.
2. **Option B**, per-user limits, right after: limit the fetcher, search and AI routes tightly, and put a generous limit on the whole authenticated group.

Both work on self-hosted instances with no new packages and no migrations. Options C (registration hardening) and D (edge or WAF) depend on what A shows and on the separate deployment repo (§5, §7). There are eleven open questions for the maintainer in §8.

No application code, config, migrations, routes or lang files were changed in this job.

## Method

- I read the code on this tree. File:line references point at `e790cf8`.
- I didn't run the app or query a database, so all statements about runtime behaviour come from reading the code. **There's no production traffic data in this sandbox, so the report can't quantify current bot traffic.** It only proposes signals and how to collect them.
- The edge setup (reverse proxy, CDN or WAF, access-log retention, and whether `TRUSTED_PROXIES` is set) lives in the separate `solyto/deployment` repo. I couldn't see it. Everything that depends on it is marked **unverified**.
- External sources, all fetched on 2026-10-14:
  - OWASP Automated Threats to Web Applications, the OAT taxonomy (`owasp.org/www-project-automated-threats-to-web-applications/`).
  - Laravel 12.x docs: `routing.md` § Rate Limiting, `rate-limiting.md`, and `pulse.md` (dashboard authorization, the `UserRequests` recorder, and Application Usage), read from `github.com/laravel/docs` branch `12.x`.
- Anything I couldn't confirm from code or a primary source is marked **unverified**.

## 1. Current controls and observability

### 1.1 Rate limiting

There are four named limiters in `app/Shared/Providers/AppServiceProvider.php`:

| Limiter | Limit | Key | Used on |
|---|---|---|---|
| `auth-login` | 5/min | `$request->email ?: ip` (`:44-46`) | `auth/login`, `auth/verify` (`routes/api.php:71-72`) |
| `auth-register` | 10/hour | IP (`:48-50`) | `auth/register` (`:70`) |
| `auth-password-reset` | 10/hour | IP (`:52-54`) | `auth/forgot-password`, `auth/reset-password` (`:73-74`) |
| `auth-passkey` | 10/min | IP (`:56-58`) | `auth/passkey/*` (`:75-76`) |

Some consequences:

- **Nothing in the `auth:sanctum` group is throttled** (`routes/api.php:80-412`). In Laravel 12 the `api` middleware group doesn't add a throttle by default, and `bootstrap/app.php:26-33` doesn't add one either.
- `auth-login` is keyed **by email when one is sent**. One IP can try 5 passwords a minute against *every* email address. That's OWASP OAT-008 (Credential Stuffing): the limit slows down attacks on one account, but not stuffing across many accounts.
- The webhook route (`routes/web.php:7-8`) and the DAV routes (`routes/web.php:10-11`) have no throttle.
- A 429 is already mapped to the standard envelope (`bootstrap/app.php:80-82`), so adding throttles needs no new error handling.
- App-level limit: `ExportController.php:29-38` allows one export per user per 24 hours. It's the only per-user quota in the API, and it's a precedent for "a limit tied to a business action".

### 1.2 Registration and email verification

- `AuthService::register` (`app/Api/Users/Services/AuthService.php:15-46`) creates the user, creates a 24-hour verification token and sends the mail. **It records no IP address, user agent or platform.** The `platform` value only goes into the mail (`:43`).
- `RegisterRequest` (`app/Api/Users/Requests/RegisterRequest.php:12-18`) has no captcha, honeypot or email-domain check. `email` only has to be `email|unique`.
- Registration returns no token (`AuthController.php:59-64`). Login refuses unverified users **only when `auth.policy.confirmation` is true** (`LoginRequest.php:31-35`). That defaults to `env('EMAIL_CONFIRMATION', true)` (`config/auth.php:115-119`). What solyto.app actually sets is **unverified**.
- Passkeys can only be registered by a user who is already signed in (`routes/api.php:87-93`), so passkey login doesn't bypass verification.
- Tokens last 7 days on web and 90 days on mobile or desktop (`AuthPlatformEnum.php:11-17`). The platform is encoded in the token name, `auth-token-{platform}` (`AuthService.php:51`).

### 1.3 Client IP and trusted proxies

`bootstrap/app.php:27-29` only trusts proxies when `TRUSTED_PROXIES` is set. **If production runs behind a reverse proxy and that variable isn't set, `$request->ip()` is the proxy's address for every client.** In that case:

- `auth-register` becomes a single global limit of 10 registrations per hour for the whole instance.
- Every IP-based signal in §4 is meaningless.

Whether it's set is **unverified**, because it's in the deployment repo. Checking this is the first item in the follow-up jobs (§7).

### 1.4 What is recorded today

| Data | Where | Per user? | Retention | Notes |
|---|---|---|---|---|
| Request count per user | Pulse `UserRequests` (`config/pulse.php:227-234`) | yes | 7 days (`:63`, `:90`) | Count only, not per route (Pulse docs § User Requests) |
| Slow requests (>1 s) | Pulse `SlowRequests` (`:209-217`) | per route | 7 days | |
| Slow outgoing HTTP (>1 s) | Pulse `SlowOutgoingRequests` (`:183-195`) | no, per URL | 7 days | No URL grouping configured, so every distinct feed or page URL is its own row |
| Jobs per user | Pulse `UserJobs` (`:219-225`) | yes | 7 days | |
| Pulse dashboard | `/pulse` | | | **No `viewPulse` gate is defined** (`grep -rn viewPulse app` → 0). Per the Pulse docs, the dashboard is then only reachable in `local`. Production data is recorded but can't be viewed |
| Token last use | `personal_access_tokens.last_used_at` (migration `2025_08_09_131945…:20`) | yes | until the token is deleted | Tokens per user, platform in `name` |
| AI usage | `ai_usage` (`AiService::saveUsageForUser`, `AiService.php:86-97`) | yes | no cleanup found | Tokens per call, feature, date |
| Who added a feed | `feeds.created_by` (migration `2026_02_20_134424…:40`) | yes | permanent | Plus `feed_subscriptions.user_id` |
| Library rows | `created_at` on links, books, music, … | yes | permanent | Bursts of creation are a signal |
| Export jobs | `export_jobs` | yes | expiry job | |
| Session IP and user agent | `sessions.ip_address`, `sessions.user_agent` (`0001_01_01_000000_create_users_table.php:32-37`) | | | **Not filled.** `SESSION_DRIVER` defaults to `redis` (`config/session.php:21`) and the API is token-based. Production value **unverified** |
| Request IP and user agent | none in the app | | | Only in reverse-proxy access logs, which is **unverified** |
| Logs | `config/logging.php` channels `single`, `bots`, `queue`, `cache`, `dav`, `daily` (14 days) | | | No channel for traffic or abuse |

Telescope is only registered in `local` (`AppServiceProvider.php:23-25`), so it's no help in production.

### 1.5 DAV Basic auth ⚑

`App\Dav\Auth\LaravelAuthBackend::validateUserPass` (`app/Dav/Auth/LaravelAuthBackend.php:11-22`) checks the email and password with `Hash::check` and nothing else:

- **There's no throttle or lockout.** The DAV routes (`routes/web.php:10-11`) have no `throttle` middleware. Every request costs a bcrypt check, so password guessing and plain request floods against `/dav` are CPU-expensive and unlimited (OAT-007 and OAT-008).
- **It doesn't check `email_verified_at`.** An account that was never verified can still use CalDAV and CardDAV, even when `auth.policy.confirmation` is on.

Changing either one touches the DAV auth path, so it's **⚑ DAV-sensitive**. This report only describes it. A throttle could be added as route middleware without touching the `pgsql` layout, but it still has to be flagged and reviewed.

### 1.6 Edge and infrastructure

Reverse proxy, CDN or WAF, bot management, access-log format and retention: **all unverified**, because they're in `solyto/deployment`.

## 2. Attack surface: endpoints that make the server do expensive or outbound work

All of these are in the `auth:sanctum` group and have **no limit** unless the table says so.

| # | Endpoint | Code | What the server does | Cost driver | What the caller gets back |
|---|---|---|---|---|---|
| 1 | `POST feeds/subscriptions` | `FeedService::createSubscription` (`FeedService.php:80-117`) | Fetches any URL with SimplePie, stores the feed, then queues `SyncFeed` | Hourly re-fetch of every subscribed feed (`SyncFeeds.php:36-41`, `routes/console.php:8`), plus **one extra page fetch per new item** for `og:image` (`FeedService.php:326`, `FeedReader.php:85-114`, 5 s timeout, UA `FeedBot/1.0`) | Item titles, descriptions, links and images via `feeds/items`. **No cap on subscriptions per user** |
| 2 | `POST feeds/test` | `FeedController::testFeed` (`FeedController.php:276-292`) → `FeedService::getTestFeedItems` (`:48-74`) | Fetches any URL right away, inside the request | One synchronous fetch per call | Up to 5 item titles and links |
| 3 | `POST libraries/links` (and quick-add commit for links) | `LibraryLinkService::create` (`:50`) → `UrlCrawlerService::fetchTitle` (`UrlCrawlerService.php:9-46`) | GET any URL, 3 s timeout | One fetch per link | Page title only |
| 4 | Library create/update with `cover_path` (books, music, movies, games, plants, recipes) | `LibraryCoverService::saveCover` (`:23`) → `getContents` (`:82-98`) | GET any URL. If the `Content-Type` is `image/*`, **the file is stored on disk** and a preview job is queued | Bandwidth plus **disk**. No size limit and no explicit timeout (Laravel's default is 30 s, **unverified** for this version) | The stored image, served by `libraries/covers/{type}/{fileName}` |
| 5 | `POST libraries/books/import/goodreads` (and quick-add) | `GoodreadsService::importFromUrl` (`GoodreadsService.php:16-19`) | GET **any URL** (no host check) and parse the HTML | One fetch per call | Parsed title, authors and so on |
| 6 | `GET libraries/{music,books,movies,games}/search/{service}/{query}` | `Library*Service::search` (music `:150`, books `:147`, movies `:143`, games `:106`) | Calls Deezer, Discogs, Spotify, Hardcover, TMDB, Steam or BGG. **Not cached** | **Shared third-party quotas and keys.** If one user exhausts them, search breaks for every user | Search results |
| 7 | `POST libraries/*/import/{service}`, `POST libraries/recipes/import/{service}` | Each `External/*Service::importFromUrl` parses an ID from the URL and calls the API (e.g. `BggService.php:22-30`, `TmdbService.php:189-197`) | Third-party API call | Shared quotas | Item metadata |
| 8 | `GET libraries/movies/{movie}/trailers` | `LibraryMovieController::trailers` (`:310-318`) | TMDB call | Shared quota | Trailer keys |
| 9 | `GET libraries/{music,books}/recommend/{type}` (type `new`) | `LibraryRecommender::new` (`LibraryRecommender.php:102-130`) → `AiService::respondStructured` | Chat completion with the **instance's** AI key (`AiService.php:19-31`) | **Money.** Usage is logged in `ai_usage`, but there's no cap | AI recommendations |
| 10 | `POST calendars/import`, `POST address-books/import` (+ `select`) | `CalendarImport::start` (`app/Dav/Services/CalendarImport.php:30`), `ContactImport::start` (`ContactImport.php:29`); HTTP via `getBasicRequest` (`:212-214` / `:218-220`) | Remote PROPFIND/REPORT to any user-given URL with Basic auth. The import itself is queued (`ImportCalendars`/`ImportContacts`, controllers `:118`) | Remote fetches plus **writes to the DAV `pgsql` store** ⚑ | Calendar and contact data |
| 11 | `GET weather/today` | `WeatherService` (`WeatherService.php:16-31`) → open-meteo | Cached per user and location | Low | Forecast |
| 12 | `POST export` | `ProcessExport` job | ZIP of all user data | CPU and disk | **Limited to 1 per 24 h** (`ExportController.php:29-38`) |
| 13 | `POST clipboard/image`, `POST users/me/profile-image`, contact photos, `POST notes/import` | Uploads | Disk and image processing | Disk | Max 5 MB / 2 MB on the image uploads (`StoreClipboardImageRequest.php:12`, `UpdateProfileImageRequest.php:12`) |
| 14 | `/dav/*` | SabreDAV | bcrypt per request, DB I/O | CPU ⚑ | Calendar and contact data |

`dashboard/quick-add/commit` doesn't add a new fetcher, but it reaches #3, #5 and #7 from free text (`QuickAddService.php:144-249`). Any limit has to cover it too.

Not user-triggered, so not a vector: `app:feeds:import` (`ImportFeeds.php:16`, operator CLI), and the scheduled release grabs (`routes/console.php:19-25`).

**Side finding, outside this scope but security-relevant:** #1–#5 and #10 fetch user-supplied URLs with no visible check against private or loopback addresses. I didn't find any host allow-list or IP filter in `UrlCrawlerService`, `FeedReader`, `GoodreadsService`, `LibraryCoverService` or the DAV import services. That's a server-side request forgery (SSRF) surface. It's separate from bot detection and should be its own security job (§7).

## 3. Threats and ranking

The brief's "users that register to run their AI crawling service through it" can mean three things:

| | Threat | OWASP OAT | Current barrier | Impact |
|---|---|---|---|---|
| **(a)** | Accounts that use solyto's outbound fetchers as a crawling proxy (hide their own IP, get scheduled fetching for free) | OAT-011 Scraping (via us), OAT-015 Denial of Service (load) | **None** once verified | Worker and queue load, outbound bandwidth, solyto's IP gets blocked or blacklisted by target sites, shared API keys get banned |
| **(c)** | High-volume automated API clients, including AI agents or scripts driving the API with a user's token | OAT-016 Skewing (stats), OAT-015 | **None** | DB and PHP-FPM load, cache churn, AI cost |
| **(b)** | Scripted mass registration to get many accounts (to spread (a) or (c) across accounts) | OAT-019 Account Creation | 10/hour per IP. Email verification if the policy is on | Many accounts, verification mail volume (sender reputation) |
| (extra) | Credential stuffing on login and DAV | OAT-008, OAT-007 | 5/min **per email** on the API. **None on DAV** | Account takeover, bcrypt CPU |

**Ranking: (a) first, (c) second, (b) third.**

- (a) is the literal reading of the brief and has the highest impact per account. The feed subscription loop (§2 #1) turns one API call into recurring hourly crawling, so a single account can cause lasting load.
- (c) shares the same root cause (no per-user ceiling), and the same fixes (options A and B) cover both.
- (b) only matters once (a) or (c) are capped per user, because then an attacker needs many accounts. The current IP throttle and verification are a partial barrier already, *if* `TRUSTED_PROXIES` is correct (§1.3).

This ranking is a judgement from the code. Without traffic data it can't be checked.

## 4. Candidate bot signals

Legend for "Data today": ✅ exists, ◐ partly or coarse, ❌ needs new collection.

### 4.1 For (a): outbound-fetcher abuse

| Signal | Data needed | Data today | False-positive risk |
|---|---|---|---|
| Many feed subscriptions per user, or many new feeds created by one user (`feeds.created_by`) | `feed_subscriptions`, `feeds` | ✅ | Low. Power users have dozens of feeds, not hundreds |
| Feeds subscribed to by only one user, pointing at non-feed or rotating URLs (many per domain, query-string variation) | `feeds.url`, `last_error` | ✅ | Medium |
| High rate of `feeds/test`, link creation, Goodreads imports or cover URLs per user | Per-user, per-route counts | ❌ (Pulse counts per user, not per route) | Low with sensible thresholds |
| Many library links per user per hour, or links to many distinct hosts | `library_links.created_at`, `url` | ✅ | Medium. Imports or bulk paste are legitimate |
| Library items created then deleted quickly (fetch, read, discard) | Delete events | ❌ (hard deletes leave no trace) | Medium |
| Outbound request volume per user | Attribution of outbound HTTP to a user | ❌ (`SlowOutgoingRequests` has no user and only slow calls) | |

### 4.2 For (c): high-volume API clients

| Signal | Data needed | Data today | False-positive risk |
|---|---|---|---|
| Request count per user far above the median | Pulse `UserRequests` | ◐ (7 days, no dashboard in prod) | **The frontend's polling** (notifications, clipboard, timers) sets the baseline. That rate is **unverified**, because the frontend is in a separate repo. Thresholds have to come from the observe-only phase |
| Flat 24-hour activity (no night gap in the user's own timezone) | Request timestamps per user, `user_settings.timezone` | ◐ (Pulse buckets, granularity **unverified**) | Medium. Shift workers, multiple devices |
| Endpoint mix: a high share of fetcher, search or AI routes compared with normal CRUD | Per-user, per-route counts | ❌ | Low |
| AI tokens per user per day | `ai_usage` | ✅ | Low |
| Search calls per user per day (§2 #6) | Per-user, per-route counts | ❌ | Low |
| Many tokens per user, or frequent new logins (scripts that log in each run) | `personal_access_tokens` (`created_at`, `last_used_at`, `name`) | ✅ | Low |
| Non-browser user agent on a `web` token | Request user agent | ❌ (not stored) | Medium. AI agents and CLI tools may be legitimate (see open question 9) |

### 4.3 For (b): scripted registration

| Signal | Data needed | Data today | False-positive risk |
|---|---|---|---|
| Registration bursts over time | `users.created_at` | ✅ | Low |
| Unverified accounts piling up (`email_verified_at IS NULL`, older than 24 h) | `users` | ✅ | Low |
| Many accounts per email domain, disposable domains, patterned local parts (`name+1@`, random strings) | `users.email` | ✅ (domain list would be new) | Medium. Company domains, `+` aliases are legitimate |
| Accounts that verify and then go straight to fetcher endpoints | Join of `users` with §4.1 | ◐ | Low |
| Many registrations from one IP or subnet | IP at registration | ❌ (not stored) | Medium (NAT, mobile carriers) |
| Verification mail bounces | Mailgun events | **unverified** (outside the app) | Low |

### 4.4 Credential stuffing (login and DAV) ⚑

| Signal | Data needed | Data today |
|---|---|---|
| Failed logins per IP across many emails | Failed-login log with IP | ❌ (failures only throw a `ValidationException`, `LoginRequest.php:23-27`) |
| Failed DAV auth per IP or username | DAV auth log | ❌. Adding it touches `LaravelAuthBackend` ⚑ |
| 429s from `auth-login` | Throttle hits | ❌ (not logged) |

## 5. Strategy options

### Option A: observe only

- Define a `viewPulse` gate (for example `role === 'super_admin'`; the `users.role` enum exists, migration `0001_01_01_000000…:20`) so the existing Pulse data becomes visible in production.
- Add a `traffic` log channel in `config/logging.php`, following the existing `bots`/`queue`/`dav` channels. Log these events with the user ID and route name: fetcher calls, search, AI, failed login, and 429s.
- Add an artisan command, e.g. `app:traffic:report`, under `app/Api/Users/Commands` or a new module's `Commands`. It ranks users by the ✅ signals in §4 (feeds per user, links and hour, `ai_usage`, tokens, unverified accounts, registration bursts, domains) and optionally reads Pulse aggregates. Schedule it daily, and optionally notify the operator (that could reuse the ntfy plan in `docs/reports/ntfy-notifications-2026-10-14.md`).

| | |
|---|---|
| Effort | Small: one gate, one channel, one command plus tests. No migration, no new package |
| Risk | Very low. It changes no behaviour |
| Privacy | Low if only user IDs and route names are logged. IPs and user agents should be opt-in (see §6) |
| Self-hosted default | Gate and command on by default. They only expose the operator's own data to their own admins |
| Gives | Real thresholds for option B, and evidence for whether (a), (b) or (c) is actually happening |

### Option B: per-user and per-endpoint rate limiting

Laravel supports this natively: `RateLimiter::for()` with `Limit::perMinute(n)->by($request->user()?->id ?: $request->ip())`, and several limits per limiter (Laravel docs § Rate Limiting, "Segmenting Rate Limits", "Multiple Rate Limits"). The existing named-limiter pattern in `AppServiceProvider.php:44-58` extends directly. The 429 mapping is already in place (`bootstrap/app.php:80-82`).

| Tier | Routes | Example shape (to be tuned with data from A) |
|---|---|---|
| `api-user` (global) | Whole `auth:sanctum` group | Generous, e.g. a few hundred a minute per user, so the frontend's polling never hits it |
| `outbound-fetch` | `feeds/test`, `feeds/subscriptions` POST, `libraries/links` POST/PUT, `*/import/*`, `quick-add/commit` | Tight per minute plus per day |
| `third-party-search` | `*/search/*`, `movies/*/trailers` | Per minute plus per day. Optionally cache results per query |
| `ai` | `*/recommend/{type}` | Per day, or a daily token budget from `ai_usage` |
| Business caps | Max feed subscriptions per user, max new feeds per user per day | App-level check like `ExportController.php:29-38` |

| | |
|---|---|
| Effort | Small to medium: limiters, route middleware, two business caps, tests (`429` paths). No migration. Config values via `config/` (STANDARDS: no `env()` outside config) |
| Risk | Medium. A limit set too tight breaks legitimate power users or the frontend. That's why A comes first, or B ships with logging-only thresholds first |
| Privacy | None. Counters live in the cache under the user ID |
| Self-hosted default | **On**, with generous values. Limits protect the instance and store no personal data. Values configurable |
| ⚑ | Throttling `/dav` is optional and DAV-sensitive. It must be flagged and reviewed separately (§1.5) |

### Option C: registration hardening

Choose any of these:

- **C1.** Keep `EMAIL_CONFIRMATION` on for solyto.app. Optionally delete accounts left unverified after N days (a scheduled job, like the existing cleanup jobs in `routes/console.php`).
- **C2.** Check `email_verified_at` in `LaravelAuthBackend` when the confirmation policy is on. ⚑ This is DAV-sensitive.
- **C3.** Disposable-email domain blocklist in `RegisterRequest`. A maintained list is a new dependency or data file (**ask before adding**).
- **C4.** Captcha or proof-of-work, e.g. Cloudflare Turnstile, hCaptcha or a self-hosted PoW. This needs **frontend changes** (separate repo), possibly a new package, and a third-party service with its own privacy impact.
- **C5.** Store a coarse registration fingerprint, e.g. the IP truncated to /24 or /48 plus the user-agent family. That needs a ⚑ migration on `users` or a new table, and a retention rule.
- **C6.** Fix the `auth-login` key to limit per IP *and* per email ("multiple rate limits", Laravel docs).

| | |
|---|---|
| Effort | C1, C6: small. C2: small but ⚑. C3: small plus a dependency decision. C4: medium, across both repos. C5: medium, with a migration |
| Risk | C4 and C3 add friction for real users. C2 can lock out existing unverified DAV users |
| Privacy | C4: a third party sees every registration. C5: personal data under GDPR, so it needs a retention period and a privacy-policy note |
| Self-hosted default | C1, C6 on. C2 follows the confirmation policy. C3, C4, C5 off and opt-in |

### Option D: edge or WAF

Per-IP connection and request limits at the reverse proxy, CDN bot management (e.g. Cloudflare), blocking known bad networks, and access-log retention for incident analysis.

| | |
|---|---|
| Effort | Depends entirely on `solyto/deployment` (**unverified**). Nothing in this repo |
| Risk | It can't see users or tokens. It only works per IP and doesn't help against a logged-in crawler on a clean IP |
| Privacy | A CDN sees all traffic. Access logs hold IPs, so they need retention rules |
| Self-hosted default | Not applicable. It's the operator's infrastructure. Document it |
| ⚑ | Covers `/dav` and `dav.solyto.de` without touching DAV code, so it's the least invasive way to throttle DAV |

### Comparison

| | Catches (a) | Catches (b) | Catches (c) | Effort | New package | Migration | Privacy impact |
|---|---|---|---|---|---|---|---|
| A | detects | detects | detects | S | no | no | low |
| B | **prevents** | partly | **prevents** | S–M | no | no | none |
| C | no | **prevents** | no | S–M | maybe (C3, C4) | maybe (C5) ⚑ | low to medium |
| D | partly (per IP) | partly | partly | infra | n/a | no | medium |

## 6. Privacy and self-hosting

- Pulse already stores user IDs only. Its dashboard resolves names and emails at render time, and shows avatars from **Gravatar** (Pulse docs § Dashboard), so opening the dashboard sends email hashes to a third party. Worth knowing before the gate is enabled. Custom card rendering can avoid it.
- **IP addresses and user agents are personal data under GDPR.** Options A and B can work without them. If C5 or IP-based signals are wanted, they should be truncated, kept for a short time (e.g. 7–30 days, in line with Pulse's 7 days) and mentioned in the privacy policy. This is a maintainer decision (open question 4).
- Self-hosted instances: limits (B) protect the operator and store nothing, so they're reasonable as an on-by-default setting with configurable values. Anything that collects extra personal data, or calls a third party (C4, D), should be off by default.

## 7. Recommendation (for decision, not decided)

**Do A, then B (or both together, with B starting in log-only mode). Decide C and D after two to four weeks of data from A.**

### Proposed follow-up jobs

1. **Check `TRUSTED_PROXIES` in production** (deployment repo, unverified). If it's missing, every IP limit in §1.1 is effectively global. Quick check, highest priority.
2. **Observe (option A):** `viewPulse` gate, `traffic` log channel, `app:traffic:report` command plus a daily schedule. Tests: the gate, and the command's ranking on factories. No migration.
3. **Per-user limits (option B):** limiters `api-user`, `outbound-fetch`, `third-party-search`, `ai`; route middleware; caps on feed subscriptions and new feeds per user; values in config. Tests for 429 per tier.
4. **Login limiter fix (C6):** per IP plus per email.
5. ⚑ **DAV auth hardening (C2 plus a throttle or edge limit):** flagged, needs separate review.
6. **SSRF guard for user-supplied URLs** (side finding §2): a shared URL validator that rejects private, loopback and link-local addresses, for `UrlCrawlerService`, `FeedReader`, `GoodreadsService`, `LibraryCoverService` and DAV import ⚑. Also a size limit and timeout for cover downloads.
7. Optionally, depending on data: unverified-account cleanup (C1), disposable-domain list (C3), captcha (C4).

### Risks

- Thresholds chosen without data from A may hit the frontend's polling or real power users.
- Per-user limits don't stop a determined attacker with many accounts. That's what (b) and C are for.
- Per-IP measures (C5, D) depend on `TRUSTED_PROXIES` being right.

## 8. Open questions for a human

1. **Which threat does the brief mean most?** (a) crawling through solyto's fetchers, (b) mass registration, or (c) high-volume API clients. The report ranks (a) > (c) > (b) (§3). Do you agree?
2. **Edge and infrastructure:** what sits in front of the API (reverse proxy, CDN or WAF), how long are access logs kept, and is `TRUSTED_PROXIES` set? (All unverified.)
3. **Is there any production signal already?** For example load spikes, the Mailgun bounce rate, or blocked outbound IPs. No traffic data was available here.
4. **Self-hosted defaults and GDPR:** should detection and limits be on by default for self-hosters, or only on solyto.app? May we store (truncated) IPs or user agents at all, and for how long?
5. **Is `EMAIL_CONFIRMATION` on for solyto.app?** (`config/auth.php:118`, default true.)
6. **⚑ DAV:** may `LaravelAuthBackend` require a verified email, and may `/dav` get an app-level throttle, or should DAV only be throttled at the edge?
7. **What rate does the frontend poll at?** Which endpoints, and how often? This sets the floor for `api-user`.
8. **What limits are acceptable for power users?** For example the maximum feed subscriptions, links per hour and AI recommendations per day.
9. **Is automated use with a personal token allowed** (scripts, AI agents managing a user's own data)? If yes, detection should target volume and endpoint mix, not "is it automated".
10. **Who looks at the report or dashboard, and how?** For example `super_admin` only, and whether to push alerts (ntfy).
11. **Should the SSRF side finding (§2) be handled before or together with the limits?**
