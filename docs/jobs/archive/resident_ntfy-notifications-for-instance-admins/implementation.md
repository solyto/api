# Implementation: ntfy notifications for instance admins

id: resident
developer: @developer
date: 2026-10-14

## Summary

There are now two operator notifications, "new user registered" and "new dev request", sent to one instance-level ntfy server and topic. They go through a new `NtfyChannel`/`NtfyMessage` pair, which follows the `TelegramChannel`/`TelegramMessage` pattern. A new `AdminNotification` base class decides whether to send: its `via()` returns `[]` unless `services.ntfy.url` and `services.ntfy.topic` are both `filled()`. When ntfy is off, nothing is built, queued or sent. `BaseNotification` was not changed.

`composer test` exits 0 (777 tests) and `composer analyse` exits 0.

## Changes

TASK-1: `config/services.php` has a new `ntfy` block. `url` comes from `env('NTFY_URL')`; `topic` and `token` come from `DockerSecretHelper::get('NTFY_TOPIC' / 'NTFY_TOKEN')`. There are no defaults and no fallback to ntfy.sh.

TASK-2: New `app/Shared/Notifications/Channels/NtfyMessage.php` with `create()`, `title()`, `message()`, and `toArray(string $topic)`. `toArray` returns exactly `topic`, `title` and `message`.

TASK-3: New `app/Shared/Notifications/Channels/NtfyChannel.php`. The topic comes from the on-demand route (`routeNotificationFor(NtfyChannel::class)`); the URL and token come from config. It sends a JSON `POST` to the server root with a 5 s timeout and adds `withToken()` only when the token is `filled()`. If the URL or topic is empty, or the notification has no `toNtfy()`, it does nothing. On a non-2xx response it logs `warning` with `status` and `host`. On a `ConnectionException` it logs `warning` with `host`. Neither path rethrows, and neither log line contains the token, headers or the request.

TASK-4: New `app/Shared/Notifications/AdminNotification.php`. It is an abstract class that extends `Notification`, uses `Queueable`, and declares `abstract toNtfy()`. Its `via()` is the on/off switch.

TASK-5: New `app/Api/Users/Notifications/UserRegisteredNotification.php` (no constructor arguments) and `app/Api/DevRequests/Notifications/DevRequestCreatedNotification.php` (`string $title`). Both implement `ShouldQueue` with `$tries = 1` and `$timeout = 30`. The comment gives the reason: the channel never throws, so a retry can't fire, and 30 s is above the 5 s HTTP timeout and below the 360 s `retry_after`.
  How title and message are split (the brief left this open):
  - Registration: title "New user registered", message "A new user account was created."
  - Dev request: title "New dev request", message "New dev request: <title>"

  Neither notification includes an email, name, id, description, screenshot or author.

TASK-6: `app/Api/Users/Services/AuthService.php` gets one `Notification::route(NtfyChannel::class, config('services.ntfy.topic'))->notify(new UserRegisteredNotification())` line. It runs after the verification mail and after the token rollback branch, so a rolled-back user is never announced.

TASK-7: `app/Api/DevRequests/Services/DevRequestService.php` gets the same kind of on-demand call with `$devRequest->title`, after the screenshot block.

TASK-8: New `tests/Unit/NtfyChannelTest.php` (`covers()` NtfyChannel, NtfyMessage and AdminNotification). It tests:
  - Off: no config, an empty-string URL or topic, and a real empty secret file read through `DockerSecretHelper` with `APP_DEBUG=false`. In each case `via()` returns `[]` and `Http::assertNothingSent()` holds.
  - On: a root-URL JSON `POST` with exactly topic, title and message.
  - Bearer token: sent when a token is set, absent for `null` and `""`.
  - Failures: a 500 response and a `ConnectionException` don't throw, and each logs the expected context without the token.

TASK-9: New describe blocks in `app/Api/Users/Tests/AuthTest.php` and `app/Api/DevRequests/Tests/DevRequestApiTest.php`:
  - Off: nothing is sent.
  - On: exactly one request, with the expected copy and no email, name or description in the body.
  - 500 and `ConnectionException`: the request still returns 201 and the row exists.

TASK-10: `docs/STANDARDS.md` has a new Decisions entry saying operator alerts (`AdminNotification` subclasses) are not user-facing under 9.4 and use English literals, like the envelope messages in 12.10. Rule 9.4 also got a one-line cross-reference, because its "no literal string in a notification" wording would otherwise read as covering these alerts.

TASK-11: Not done, as the analyst recommended. This repo has no `.env.example`; it lives in the separate `localdocker` repo. See the follow-ups below.

## Known issues / follow-ups

- Cross-repo follow-up: add `NTFY_URL`, `NTFY_TOPIC` and `NTFY_TOKEN`, commented out, to `.env.example` in the `localdocker` repo (TASK-11).
- Cross-repo follow-up: the `selfhosted` and `deployment` changes listed in the brief (secrets, env vars and `setup.sh` `write_empty` entries for the `api`/`php` and `queue` services).
- Environment note: this worktree had no `vendor/` and no `storage/framework/*`. I ran `composer install` from the lock file (no new packages) and created the gitignored storage directories. The very first `composer test` run after that had 2 failures in `app/Api/Export/Tests/ExportTest.php` (`ErrorException`). The file passes on its own, and the next two full runs were green. This looks like a first-run effect of the freshly created `storage/` and unrelated to this job, but I didn't investigate further.
- All test runs print warnings about the missing `/workspace/.env` file. These come from the sandbox (`.env.testing` is mounted as `/dev/null` here) and aren't caused by these changes.
