# Implementation: Alert admins when a user hammers the API

id: fifteen
developer: @developer
date: 2026-10-14

## Summary

Authenticated `v1` requests are now counted per user with `RateLimiter::hit("api-traffic:{$userId}", 60)` in a new route middleware. When a user goes over the threshold (default 300/min), the middleware writes one entry to a new `traffic` log channel and sends one `ApiTrafficAlertNotification` to the admin ntfy topic. Both are deduplicated per user with `Cache::add("api-traffic-alerted:{$userId}", …)` for the cooldown (default 3600 s). The middleware only observes: it always returns `$next($request)` and never returns a 429.

## Changes

TASK-1: `config/services.php` has a new `traffic` block with `alert_threshold` (`API_TRAFFIC_ALERT_THRESHOLD`, default 300) and `alert_cooldown` (`API_TRAFFIC_ALERT_COOLDOWN`, default 3600). Both are cast to int, and `env()` stays inside `config/`.
TASK-2: `config/logging.php` has a new `traffic` channel, copied from `bots`. It writes to `storage/logs/traffic.log`.
TASK-3: New `app/Shared/Notifications/ApiTrafficAlertNotification.php`. It extends `AdminNotification`, implements `ShouldQueue`, and sets `$tries = 1` and `$timeout = 30` with the same why-comment as `UserRegisteredNotification`. Its constructor takes the user ID (a **string**, because user IDs are UUIDs) and the count. The English message names the user ID and the count only, with no email or name. It sits in `app/Shared/` as the analyst suggested, because it's cross-cutting.
TASK-4: New `app/Shared/Middleware/TrackApiTraffic.php`, next to `RecordClientVersion`. It's registered as route middleware on the `v1` `auth:sanctum` group in `routes/api.php`, listed after `auth:sanctum` (the one-line change plus a `use`). The log context is `user_id`, `count`, and `route`. Most routes have no name, so `route` falls back to the route URI pattern (e.g. `api/v1/users/me`), which contains no IDs. `routes/web.php` is untouched.
TASK-5: New `app/Api/Users/Tests/ApiTrafficAlertTest.php`, next to `ClientVersionHeaderTest.php`. It covers the six cases from the brief with threshold 3. The `traffic` channel is pointed at a Monolog `TestHandler` so the log entries can be asserted. The unauthenticated case uses `RateLimiter::spy()` on `GET /api/v1/health`.
TASK-6: `composer test` and `composer analyse` both exit 0. No baseline entries were added.

## Known issues / follow-ups

- Owner confirmation is still open, as carried over in tasks.md: (1) the ntfy message contains the user ID; (2) the config lives in `config/services.php` → `traffic`.
- The middleware runs on every authenticated request. If the cache store fails (Redis down), it throws and the request fails. Per the task, there is deliberately no try/catch.
- Worktree setup: `vendor/` and `storage/framework/*` were missing, so I ran `composer install` from the lock file (no new packages) and created the gitignored storage dirs. The first full `composer test` run after that reported 2 failures. Seven later full runs were all green, so this looks like first-run storage/DAV-sqlite setup, not this change. All tests emit an environmental warning (`/workspace/.env` missing).
- `.env.testing` shows as modified, but it's a character-device mount in this worktree (git can't hash it). It wasn't touched and isn't committed.
