# Tasks: ntfy notifications for instance admins

id: resident
analyst: @analyst
date: 2026-10-14

<!-- Produced by @analyst from brief.md. -->

Branch checked: worktree HEAD is `feature/resident_ntfy-notifications-for-instance-admins`, which matches `brief.md`.

## Task breakdown

TASK-1: Add a `ntfy` block to `config/services.php`: `url` from `env('NTFY_URL')`, and `topic` and `token` from `DockerSecretHelper::get('NTFY_TOPIC')` / `get('NTFY_TOKEN')`, with no defaults and no ntfy.sh fallback.
  files: config/services.php
  depends: none
  risk: low. It's an additive config block that follows the existing `hardcover`/`mailgun` pattern. Note that `DockerSecretHelper::get()` returns `""` for an empty secret file, `null` when the secret isn't set, and `false` (via `getenv`) under `APP_DEBUG=true` when the variable is missing. Consumers must use `filled()`, not `!== null`.

TASK-2: Add `App\Shared\Notifications\Channels\NtfyMessage`, a small builder modelled on `TelegramMessage`. It has `create()`, `title()` and `message()`, plus a `toArray(string $topic)` that returns exactly `topic`, `title` and `message`.
  files: app/Shared/Notifications/Channels/NtfyMessage.php (new)
  depends: none
  risk: low. It's a pure value object with no I/O. Don't add priority/tags/click/cache fields; the brief doesn't ask for them.

TASK-3: Add `App\Shared\Notifications\Channels\NtfyChannel::send(object $notifiable, Notification $notification)`. It calls `toNtfy()`, checks the result is an `NtfyMessage`, and `POST`s the JSON to the configured server root. It uses a short `Http::timeout(...)` and adds `withToken()` only when `filled(config('services.ntfy.token'))`. The topic comes from `$notifiable->routeNotificationFor(NtfyChannel::class)` or from config (developer's choice, but stay consistent with TASK-5). On a non-2xx response it logs the status and the URL host only. It catches `ConnectionException` and logs the host, without rethrowing.
  files: app/Shared/Notifications/Channels/NtfyChannel.php (new)
  depends: TASK-1, TASK-2
  risk: medium. Tests run with `QUEUE_CONNECTION=sync` (phpunit.xml), so a queued notification runs inline, and a `ConnectionException` that escapes the channel would turn `POST /auth/register` into a 500 in tests. The brief requires that it doesn't break the request, so the channel must catch it. The log line must never include the token, headers or the request object.

TASK-4: Add an abstract `App\Shared\Notifications\AdminNotification extends Notification` next to `BaseNotification`, using `Queueable`. Its `via()` returns `[NtfyChannel::class]` only when `filled()` URL and topic are both configured, and `[]` otherwise. `BaseNotification` is not touched.
  files: app/Shared/Notifications/AdminNotification.php (new)
  depends: TASK-1, TASK-3
  risk: low. This new base class is explicitly decided in the brief. `via()` runs before queueing (Laravel `NotificationSender`), so `[]` means nothing is queued and no call goes out.

TASK-5: Add `UserRegisteredNotification` (in `app/Api/Users/Notifications/`) and `DevRequestCreatedNotification` (in `app/Api/DevRequests/Notifications/`). Both extend `AdminNotification` and implement `ShouldQueue`, with explicit `$tries`/`$timeout` and a one-line reason (STANDARDS 9.2: `$timeout` < 360 s `retry_after`). Their constructors take scalars only: none for registration, `string $title` for the dev request. `toNtfy()` returns English literal copy: title "New user registered", and "New dev request: <title>".
  files: app/Api/Users/Notifications/UserRegisteredNotification.php (new), app/Api/DevRequests/Notifications/DevRequestCreatedNotification.php (new)
  depends: TASK-2, TASK-4
  risk: low. Small classes. The registration message must hold no email, name or id, and the dev request message must hold no description, screenshot or author. The title is sent in full, as the brief decides. Unclear: the brief doesn't say how `title` vs `message` are split for each event. Pick one and state it in implementation.md.

TASK-6: Hook the registration event: at the end of `AuthService::register`, after the `Mail::to(...)->send(...)` line and before `return`, add one on-demand call (`Notification::route(NtfyChannel::class, …)->notify(new UserRegisteredNotification())`).
  files: app/Api/Users/Services/AuthService.php
  depends: TASK-5
  risk: low. It's one line at a fixed point. It must come after the token/rollback branch (`:38-41`), so a rolled-back user is never announced.

TASK-7: Hook the dev request event: in `DevRequestService::create`, after the screenshot block and before `return`, add one on-demand call with `$devRequest->title`.
  files: app/Api/DevRequests/Services/DevRequestService.php
  depends: TASK-5
  risk: low. It's one line. Check that the `title` attribute name matches the `DevRequest` model/request.

TASK-8: Tests for the channel and the off/on switch. Disabled, both with no config and with the empty-string topic/URL (the empty secret-file case), means `via()` gives `[]` and nothing is sent. Enabled means a `POST` to the root URL with JSON keys `topic`, `title` and `message` only. `Authorization: Bearer` is present when a token is set, and absent for both a `null` and a `""` token. A 500 response and a `ConnectionException` don't throw. The failure log has no token in it (e.g. `Log::spy()`/`Log::shouldReceive`).
  files: tests/Unit/NtfyChannelTest.php (new; name and location per TESTING.md "shared contracts in tests/Unit with covers()")
  depends: TASK-3, TASK-4, TASK-5
  risk: low. It uses `Http::fake()` only, and config is set with `config([...])` per test so nothing leaks.

TASK-9: API tests for the hooks. With ntfy off and `Http::fake()`, both `POST /api/v1/auth/register` and `POST /api/v1/dev-requests` succeed and `Http::assertNothingSent()` holds. With ntfy on, each sends exactly one ntfy request with the expected title/message and no email, name or description in the body. With ntfy on and a 500 or `ConnectionException`, registration still returns 201 and the user exists, and dev request create still succeeds.
  files: app/Api/Users/Tests/AuthTest.php (or a new `app/Api/Users/Tests/NtfyRegistrationTest.php`), app/Api/DevRequests/Tests/DevRequestApiTest.php
  depends: TASK-6, TASK-7, TASK-8
  risk: medium. "Nothing sent" relies on the mailer being `array` in phpunit.xml, and on no other HTTP in these two request paths (the dev request screenshot path must not use `Http`). If a third-party HTTP call shows up, the assertion has to filter by the ntfy URL instead.

TASK-10: Add a STANDARDS.md `## Decisions` entry. Operator alerts sent through `AdminNotification`/ntfy are not user-facing messages under rule 9.4. They're English-only literals and don't extend `BaseNotification`, like the English-only envelope messages in 12.10. Add a one-line cross-reference in 9.4 only if the developer judges the rule ambiguous without it.
  files: docs/STANDARDS.md
  depends: none
  risk: low. It's a docs change explicitly required by the brief. It must stay a narrow exception and not loosen 9.4 for user notifications.

TASK-11 (BLOCKED, needs clarification): Add `NTFY_URL`, `NTFY_TOPIC` and `NTFY_TOKEN`, commented out, to `.env.example`.
  files: .env.example (does not exist in this repo)
  depends: none
  risk: low (scope unclear). This repo has no `.env.example` (only `.env.testing`). `composer.json:62` and `README.md:32` reference one, and the archived `post_spotify-integration/follow-up.md` shows it lives in the separate `localdocker` repo. Creating a new `.env.example` here would expand scope. The developer should NOT create it. Record it in implementation.md as a cross-repo follow-up next to the selfhosted/deployment items, unless a human says otherwise.

## Out of this repo (not tasks here)
The `selfhosted` and `deployment` changes listed in the brief, plus `.env.example` in `localdocker` (TASK-11), are done outside this repo.

## Done criteria
`composer test` and `composer analyse` both exit 0 in the worktree. No migration, route, OpenAPI, lang file or `BaseNotification` change. No change to the `pgsql`/DAV side.
