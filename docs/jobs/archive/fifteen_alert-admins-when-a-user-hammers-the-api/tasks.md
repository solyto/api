# Tasks: Alert admins when a user hammers the API

id: fifteen
analyst: @analyst
date: 2026-10-14

<!-- Produced by @analyst from brief.md. -->

## Pre-flight findings

- Branch checked: the worktree HEAD is `feature/fifteen_alert-admins-when-a-user-hammers-the-api`, which matches `brief.md`.
- **The brief's "no middleware exists yet" point no longer applies.** `app/Shared/Middleware/RecordClientVersion.php` exists (client-version-header, owner-approved). The new middleware goes next to it in `app/Shared/Middleware/`, so no new kind of class is introduced.
- **Line numbers have moved.** The `v1` `auth:sanctum` group now starts at `routes/api.php:86`, not `:80`.
- **Registration must be route middleware, not `appendToGroup('api', …)`.** `RecordClientVersion` is appended to the `api` group, which runs *before* the route's `auth:sanctum` (see its comment), so it only sees the user after `$next`. The brief requires the counter to run after `auth:sanctum` and to skip unauthenticated routes. The middleware is therefore added to the existing `Route::prefix('v1')->middleware('auth:sanctum')` group, listed after `auth:sanctum`. It is not registered in `bootstrap/app.php`.
- Tests use `CACHE_STORE=array` and `QUEUE_CONNECTION=sync` (`phpunit.xml`). `RateLimiter` and `Cache::add` work against the array store, and the queued notification runs inline, so `Http::fake()` + `Http::assertSentCount()` works the same way as in `AuthTest.php:94-116`.
- `RateLimiter::hit($key, 60)` gives a fixed 60 s window that starts at the first hit, not a calendar minute. This is fine for an alert. It's noted here so nobody "fixes" it.
- STANDARDS Decisions (2026-10-14): operator alerts extend `AdminNotification` and use English literals. This matches the brief.

## Open for the owner (carried over, not resolved here)

1. **User ID in the ntfy message.** This plan follows the brief's default: the message names the user ID and nothing else (no email, no name). If the owner chooses "no identifier", only the `toNtfy()` text in TASK-3 and one test assertion in TASK-5 change.
2. **Config location.** This plan follows the brief's suggestion: a `traffic` block in `config/services.php` next to `ntfy`. Moving it later only changes the `config()` keys.

Neither point blocks the structure of the work. The reviewer should get both confirmed before merge.

## Task breakdown

TASK-1: Add a `traffic` config block in `config/services.php` with `alert_threshold` (env `API_TRAFFIC_ALERT_THRESHOLD`, default 300) and `alert_cooldown` in seconds (env `API_TRAFFIC_ALERT_COOLDOWN`, default 3600), cast to int.
     files: config/services.php
     depends: none
     risk: low — additive config keys. `env()` stays inside `config/` (STANDARDS 12.5) and no secrets are involved.

TASK-2: Add a `traffic` log channel to `config/logging.php`, copied from `bots` (driver `single`, `storage/logs/traffic.log`, same level/placeholders).
     files: config/logging.php
     depends: none
     risk: low — additive channel, and no existing channel changes.

TASK-3: Create `ApiTrafficAlertNotification` extending `AdminNotification` and implementing `ShouldQueue`, with `$tries = 1`, `$timeout = 30` and the same why-comment as `UserRegisteredNotification`. It takes the user ID and the request count as constructor args (ids, not models: STANDARDS 9.1), and `toNtfy()` returns an English title and message containing the user ID and count.
     files: app/Shared/Notifications/ApiTrafficAlertNotification.php (new)
     depends: none
     risk: low — mirrors two existing subclasses. Placing it in `app/Shared/Notifications/` (not a module) is a judgement call: the alert is cross-cutting and belongs to no module. Flag it in the implementation notes if the developer places it differently.

TASK-4: Create middleware `App\Shared\Middleware\TrackApiTraffic` and add it to the `v1` `auth:sanctum` group in `routes/api.php` after `auth:sanctum`. It calls `RateLimiter::hit("api-traffic:{$userId}", 60)`, and once the returned count exceeds `config('services.traffic.alert_threshold')` and `Cache::add("api-traffic-alerted:{$userId}", true, $cooldown)` succeeds, it writes one `Log::channel('traffic')` entry (user ID, count, route name) and sends the notification via `Notification::route(NtfyChannel::class, config('services.ntfy.topic'))->notify(...)`. It always returns `$next($request)` unchanged and never a 429.
     files: app/Shared/Middleware/TrackApiTraffic.php (new), routes/api.php (the group's `->middleware(...)` call at line 86, plus a `use` line)
     depends: TASK-1, TASK-2, TASK-3
     risk: medium — it runs on every authenticated request, so an exception here (e.g. a cache/Redis failure) would break the whole API. Keep the logic minimal, and keep the edit to `routes/api.php` to that one line. `routes/web.php` (DAV, Telegram ⚑) must not be touched.

TASK-5: Add Pest tests co-located per `docs/TESTING.md`, covering the six cases from the brief's Notes. They use a low threshold set via `config()`, `Http::fake()`, and ntfy configured as in `AuthTest.php:104`. The cases: below threshold → no HTTP call and no log entry; crossing → exactly one ntfy call, response 2xx; still above within cooldown → no second call; two users → counted and alerted independently; ntfy unconfigured → `Http::assertNothingSent()` and request succeeds; an unauthenticated route (e.g. `GET /api/v1/health`) → no counter key is created.
     files: app/Api/Users/Tests/ApiTrafficAlertTest.php (new; suggested location, next to ClientVersionHeaderTest.php, which tests the sibling middleware). The developer should confirm it against TESTING.md "Layout and suites"
     depends: TASK-4
     risk: low — test-only. One thing to watch: the auth guard is cached between requests in one test (see `forgetGuards()` in `ClientVersionHeaderTest.php:32`), and the log assertion needs `Log::spy()`/`shouldReceive` on the `traffic` channel.

TASK-6: Run `composer test` and `composer analyse` in the worktree and fix anything the new code introduces. Do not add baseline entries for new code.
     depends: TASK-1 … TASK-5
     risk: low — verification only.

## Explicitly not in this job

Blocking/throttling, `routes/web.php` (DAV/Telegram ⚑), public routes, DB-signal reports, the SSRF guard, Pulse changes (brief: Out of scope). No migration and no new package.
