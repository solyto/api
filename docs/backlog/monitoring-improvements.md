# Backlog: improve production monitoring

created: 2026-11-17

## What

Decide how to extend production monitoring beyond today's Pulse setup, so
problems can be traced to the specific feed, calendar, user or request, not
only to a route or job class. This entry collects the options discussed in the
Pulse review (2026-11-17). No option is chosen yet.

## Current state

Laravel Pulse, enabled since 1.1.3 (`config/pulse.php`):

- All recorders on, sample rate 1, 1000 ms thresholds for slow requests,
  queries, jobs and outgoing requests. No `PULSE_*` overrides in
  `deployment/`.
- Ingest driver `storage` (default): every request writes its entries to the
  database at the end of the request (after the response). Retention 7 days.
- Dashboard (`resources/views/vendor/pulse/dashboard.blade.php`) had all
  cards at the time of the review, but **Servers** and **Usage** showed no
  data (see option A for what was decided):
  - Servers needs a long-running `php artisan pulse:check` process. There is
    no such service in `deployment/templates/api/compose.yml.j2`.
  - Usage: the `UserRequests` / `UserJobs` recorders are enabled, but the card
    shows nothing. Cause not investigated.
- The cache card mixes in rate limiter counters: 32-character MD5 keys from
  `ThrottleRequests` (`md5($limiterName.$key)`), which Pulse's
  `defaultVendorCacheKeys()` doesn't filter. They sit around 50% hit rate and
  pull the overall rate down (69.73%, while the app caches are mostly 90–98%).
  Per-user keys aren't grouped either (`'/:\d+/' => ':*'` is commented out).

What Pulse could not answer in the review (aggregates only, no per-event
detail):

- which feed a 142 s `SyncFeed` run belonged to (`feed-sync-slow-jobs.md`)
- which external calendars made calendar requests take 13–15 s
  (`external-calendars-parallel-remote-state.md`)
- the full request context of exceptions (e.g. the passkey 500s, which turned
  out to be a regression from `0d932a3`)

## Options

### A. Tune Pulse (decided and done, 2026-11-17)

- **Servers card: not needed.** The Proxmox host already has monitoring for
  CPU, memory and disk. (It would need a long-running
  `php artisan pulse:check` service and `procps` in the PHP image, since the
  recorder reads CPU via `top`. One instance would report the whole host,
  because containers share the kernel.) The card hides itself without data.
- **Usage card: off** (`57ded7b`). The `UserRequests` / `UserJobs` recorders
  now default to `false` and the card is removed. It ranks users by name,
  which we don't want to track.
- **Cache card: rate limiter keys ignored** (`57ded7b`):
  `'/^[0-9a-f]{32}(:timer)?$/'` in the `CacheInteractions` `ignore` list. No
  app cache key is an md5 hash.
- **Slow outgoing requests grouped by host: rejected.** It would merge the
  og:image lookups per site, but loses the exact feed URL, and still misses
  lookups under 1 s and the link to the feed sync. Per-feed measurement
  (option B) answers that better.
- **Still open, if load grows:** lower
  `PULSE_CACHE_INTERACTIONS_SAMPLE_RATE` (e.g. 0.1), or switch to Redis
  ingest (`PULSE_INGEST_DRIVER=redis` plus a `php artisan pulse:work`
  process).

### B. Custom Pulse recorders and cards (done, 2026-11-17)

Built in `App\Shared\Pulse`: `PulseMetrics` records, `Cards\*` display,
`PulseMetricsServiceProvider` wires the listeners. Cards: feed sync per feed
(with og:image lookups), external calendars per host, DAV requests per method
and client, notifications per channel, client versions (from the tokens, plus
426 count), rate limits (429 per limiter and route), release lookups, and
queue load (queue length per minute from the scheduler, wait time per job).
The dashboard is grouped into sections (`components/pulse-section.blade.php`).
Production ingests through Redis (`PULSE_INGEST_DRIVER=redis` in the `.env`,
`pulse` service running `pulse:work` in `deployment`); local stays on direct
database writes. Tests for the recorders and cards are still to do.

Original notes:


`Pulse::record($type, $key, $value)->max()->count()` plus a small Blade card:

- `SyncFeed` duration and og:image lookup count per feed
- remote call duration per external calendar host
- release lookups per user (live fetch on cache miss)

Still aggregates, but keyed by the thing we need to identify. No new
infrastructure, no third party.

### C. Telescope with strict filters (small to medium)

Stores individual events (requests, queries, jobs, exceptions, outgoing
requests) in our own database, which makes it the closest to Nightwatch
without a third party.

- Production only with `Telescope::filter()` (failed and slow requests,
  exceptions, failed jobs), short retention (`telescope:prune`) and redaction
  (`hideRequestParameters`, headers).
- Downsides: stores request bodies and query bindings, i.e. users' personal
  data. One row per event means noticeably more writes than Pulse. Built for
  development. Better suited to a temporary debugging session than to
  permanent monitoring.

### D. OpenTelemetry with a self-hosted backend (medium to large)

The standard for traces (request → queries → outgoing requests → jobs as a
timeline).

- App: the PHP `opentelemetry` extension in the Docker image, plus the
  OpenTelemetry Laravel auto-instrumentation. Export at the end of the
  request to a **local** OTel collector (never directly to a remote, or the
  worker waits on the network). The collector forwards in batches.
- Backends (all self-hostable): **SigNoz** (traces, logs and metrics in one
  UI, ClickHouse-based, several GB RAM), **Grafana stack** (Tempo, Loki,
  Prometheus, Grafana; flexible, more pieces), **Jaeger** (traces only, lean).
- Needs sampling and redaction config. Mostly work in `deployment/` and
  `dockerhub/api/api-php.Dockerfile`. Should stay optional, and off for
  self-hosters by default.

### E. Error tracking: GlitchTip or Sentry (small in the app, medium in ops)

- `sentry/sentry-laravel` SDK, with full exception context and breadcrumbs.
- **GlitchTip:** Sentry-compatible, lightweight to self-host, strong on
  errors, basic performance features.
- **Sentry self-hosted:** excellent, but heavy (many containers, a lot of
  RAM). Probably too much for this instance.

### F. Laravel Nightwatch (managed only)

- Individual events and request timelines. The app hands events to a local
  agent (`php artisan nightwatch:agent`), which ships them in batches, so no
  extra database writes and no network wait in the request.
- **Not self-hostable:** the backend is fully managed (US, EU or Australia
  region). The agent is open source.
- Downsides: it sends request URLs, user identities and exception context to
  a third party. That needs a data processing agreement, a privacy policy
  update and redaction config. Self-hosters would need their own accounts.
  Costs money, priced by event volume.
- `minitor/agent` (community) replaces the agent and forwards to a
  self-hosted server. Not official; would need a closer look before use.

## Overhead (for all options)

All of these do their writing or sending after the response
(terminate phase), so response times barely change, but the PHP worker stays
busy a little longer, which costs throughput under load.

- Pulse and Nightwatch: typically single-digit milliseconds of worker time per
  request.
- OpenTelemetry: similar, as long as it exports to a local collector.
- Telescope without filters: the most, growing with queries per request.

At today's traffic, none of A, B or E would be measurable. D adds a backend
that needs its own resources.

## Decisions

- A and B: done (see above).
- C–F: undecided. The leaning from the discussion was **B first** (small, no data
  leaves the server, closes today's blind spots), **D (OpenTelemetry →
  SigNoz)** if traces are needed later, **C** only temporarily for a specific
  debugging session. **F** conflicts with the privacy focus and self-hosting.

## Related

- `feed-sync-slow-jobs.md` (option 4 there: measure per-feed durations)
- `external-calendars-parallel-remote-state.md`
- `library-releases-live-fetch.md`

## Sources

- Nightwatch FAQs: https://nightwatch.laravel.com/docs/guides/faqs
- Nightwatch start guide (data regions): https://nightwatch.laravel.com/docs/start-guide.md
- Nightwatch changelog: https://nightwatch.laravel.com/docs/resources/changelog
- minitor/agent: https://root.packagist.org/packages/minitor/agent
