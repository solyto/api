# Backlog: Alert admins when a user hammers the API

created: 2026-10-14

## What
Count authenticated API requests per user and notify the instance admins through ntfy when one user crosses a threshold. Observe only: no request is ever blocked or slowed down.

1. **Count:** a middleware on the `v1` `auth:sanctum` group (`routes/api.php:80`) calls `RateLimiter::hit("api-traffic:{$userId}", 60)` on every request. It runs after `auth:sanctum`, so the user is known. It never returns a 429.
2. **Threshold:** when the count for the current minute crosses the threshold (default 300 requests per minute, configurable), the middleware:
   - writes one entry to a new `traffic` log channel in `config/logging.php`, modelled on `bots`: user ID, count, route name.
   - sends one `ApiTrafficAlertNotification` (extends `AdminNotification`, `ShouldQueue`, `$tries = 1`, `$timeout = 30`, like `UserRegisteredNotification`), on demand: `Notification::route(NtfyChannel::class, config('services.ntfy.topic'))->notify(...)`, as in `AuthService::register`.
3. **No spam:** at most one alert per user per hour (configurable), deduplicated with `Cache::add("api-traffic-alerted:{$userId}", true, $cooldown)`. The log entry follows the same cooldown.
4. **Config:** threshold and cooldown as config values read through `config()`, from `env()` only inside `config/` (STANDARDS), with the defaults above.

## Why
The owner wants to know whether something (a script, an AI agent, a bot account) is hammering the API, without restricting users. Today there's no per-user request data in production: Pulse isn't running there, and requests aren't logged per user. Source: `docs/reports/bot-traffic-detection-2026-10-14.md` §1.4, §4.2.

## Decisions
- Observe and notify only. Limiting is a separate decision for later. The same counter key can then back a blocking limiter.
- No Pulse. A plain Redis counter through `RateLimiter` needs no migration, no package and no dashboard.
- ntfy delivery reuses `AdminNotification` / `NtfyChannel`. When ntfy isn't configured, `via()` returns `[]` and only the log entry is written.
- Default threshold 300/min, deliberately high, to be lowered once the normal frontend polling rate is known.
- Feed crawling is not covered and needs no separate job for now. `FeedService::createSubscription` only stores URLs that SimplePie parses as feeds with items, so plain pages can't be subscribed to for hourly re-crawling. The report's §2 #1 overstates this.

## Out of scope
- Blocking or throttling of any kind (report option B).
- `routes/web.php`: DAV and the Telegram webhook ⚑.
- Public routes (auth, health). They already have per-IP limiters.
- A daily report of DB signals (feeds, links, `ai_usage` per user).
- The SSRF guard for user-supplied URLs (report §2 side finding).
- Turning Pulse off explicitly (`PULSE_ENABLED` defaults to `true` in `config/pulse.php:46`).

## Open for the owner
- **The ntfy message identifies the user by ID.** That's a deliberate exception to the anonymous-content decision in the ntfy job, since the admin needs to know whom to look at. No email or name. Confirm, or send no identifier and keep the user ID in the log only.
- **No middleware exists in `app/` yet.** `client-version-header` plans the first one under `app/Shared/` and has owner approval. If this entry is done first, it adds that kind of class, so it needs the same approval and goes in the same place.
- **Where the config keys live.** No suitable file exists; e.g. a `traffic` block in `config/services.php` next to `ntfy`.

## Notes
Tests per `docs/TESTING.md`, with `Http::fake()` and ntfy configured unless stated otherwise:
- Below the threshold: no notification and no log entry.
- Crossing the threshold: exactly one notification; the response is still 2xx.
- Staying above the threshold within the cooldown: no second notification.
- Two different users: counted and alerted separately.
- ntfy not configured: nothing sent (`Http::assertNothingSent()`), and the request still succeeds.
- Unauthenticated routes are not counted.

Copy is English-only, like the other admin notifications.

Check: `composer test` and `composer analyse` exit 0.
