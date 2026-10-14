# Verdict: Alert admins when a user hammers the API

id: fifteen
reviewer: @reviewer
date: 2026-10-14

## Review

Reviewed `git diff main...HEAD` on `feature/fifteen_alert-admins-when-a-user-hammers-the-api`. Changed source files: `config/services.php`, `config/logging.php`, `routes/api.php`, `app/Shared/Middleware/TrackApiTraffic.php` (new), `app/Shared/Notifications/ApiTrafficAlertNotification.php` (new), `app/Api/Users/Tests/ApiTrafficAlertTest.php` (new), plus job docs. Nothing changed outside `implementation.md`'s account. `routes/web.php`, the `pgsql`/DAV setup and `bootstrap/app.php` are untouched. The "chore: commit all" commits only hold job docs.

TASK-1: PASS
notes: `config/services.php` has a `traffic` block with `alert_threshold` (env `API_TRAFFIC_ALERT_THRESHOLD`, 300) and `alert_cooldown` (env `API_TRAFFIC_ALERT_COOLDOWN`, 3600), both cast to int. `env()` is only used inside `config/`.

TASK-2: PASS
notes: `config/logging.php` has a `traffic` channel that is an exact copy of `bots` (single driver, `storage/logs/traffic.log`).

TASK-3: PASS
notes: `ApiTrafficAlertNotification` extends `AdminNotification`, implements `ShouldQueue`, sets `$tries = 1` and `$timeout = 30`, and has the same why-comment. The constructor takes plain scalars (`string $userId`, because IDs are UUIDs, and `int $count`), not models. The message is English and names only the user ID and count. `via()` is inherited, so it returns `[]` when ntfy isn't configured.

TASK-4: PASS
notes: `TrackApiTraffic` calls `RateLimiter::hit("api-traffic:{$userId}", 60)`. It alerts only when `count > threshold` and the `Cache::add("api-traffic-alerted:{$userId}", true, $cooldown)` call succeeds, so the log entry and the notification share the cooldown. The log context has `user_id`, `count` and `route` (the route name, falling back to the URI pattern). The notification is sent on demand through `Notification::route(NtfyChannel::class, config('services.ntfy.topic'))`. The middleware always returns `$next($request)`. In `routes/api.php` it is registered on the `v1` `auth:sanctum` group, after `auth:sanctum`, and the public `v1` group (health, auth) doesn't get it. As the developer notes, a cache failure isn't caught. That matches the task, which asked for minimal logic.

TASK-5: PASS
notes: All six cases from the brief are covered: below threshold, crossing (exactly one call, 2xx, no email/name in the body, log context), cooldown, two users, ntfy unconfigured (`assertNothingSent`, the log entry is still written, the request succeeds), and the unauthenticated route (`RateLimiter::spy()` on `/api/v1/health`). All six pass when run on their own.

TASK-6: PASS
notes: I re-ran both checks in the worktree myself. `composer test` exits 0 (2339 assertions, no failures). The warnings come from the environment: every test, old and new, warns about the missing `/workspace/.env` read. `composer analyse` exits 0 ("No errors"), and no baseline entries were added.

## Security

Not run separately. The ntfy payload contains only the user ID and the count (the test asserts that the email and name are absent). No secrets are logged or committed. The `.env.testing` working-tree change is a mount artefact and isn't in any commit.

## Overall

APPROVED

No blockers. The two owner questions carried over from the brief and tasks.md are still open: (1) whether the ntfy message should contain the user ID, and (2) whether the config should live in `config/services.php` → `traffic`. These are owner product decisions, not correctness defects. If either changes, it's a one-line edit to the message text or the config keys.
