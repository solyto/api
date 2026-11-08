# Tasks: change ntfy registration reports

id: couple
analyst: analyst
date: 2026-11-08

## Context

- Today, `AuthService::register` (`app/Api/Users/Services/AuthService.php:49`) sends one `UserRegisteredNotification` per registration. It sends on demand to `services.ntfy.topics.registrations ?: services.ntfy.topic`.
- `UserRegisteredNotification` (`app/Api/Users/Notifications/UserRegisteredNotification.php`) extends `AdminNotification`, implements `ShouldQueue`, and sets `$tries = 1` / `$timeout = 30` with a why-comment. `AdminNotification::via()` returns `[]` when ntfy isn't configured, so nothing gets sent.
- Rules: STANDARDS 9.3 (commands go in `app/Api/<Module>/Commands`, and `app/Api/Users/Commands` is already registered in `bootstrap/app.php`; scheduling goes in `routes/console.php`, grouped by module), the 9.4 / Decisions entry (operator alerts use English literals, no lang files and no translator), and TESTING.md ("Jobs and commands — assert the effect", `app/Api/Users/Tests/CommandTest.php`).
- Keep the `services.ntfy.topics.registrations` config / `NTFY_TOPIC_REGISTRATIONS` secret. The daily summary uses the same topic with the same fallback.
- `User` has no `SoftDeletes`, so "registered in the last 24 hours" means `users.created_at >= now()->subDay()`. Users who registered and then deleted their account are not counted.

## Open questions (not blocking; the developer follows the stated default unless the owner says otherwise)

1. **Zero registrations:** should a "0 users registered" message still go out? Default: **yes, send it**. The brief wants daily visibility, and a missing message would look the same as a broken scheduler. If the owner prefers silence on zero, this is a one-line change.
2. **Schedule time:** the brief doesn't give one. Default: `dailyAt(8)`, a morning report in app timezone. Other daily jobs run at 2–5.
3. **Verified vs. all:** the default counts every created account, verified or not, which matches the old per-registration behaviour.

## Task breakdown

TASK-1: Remove the per-registration ntfy call from `AuthService::register`, along with its now-unused imports (`UserRegisteredNotification`, `NtfyChannel`, and `Notification` if nothing else uses it).
  files: app/Api/Users/Services/AuthService.php
  depends: none
  risk: low — one line and its imports go; the verification mail and user creation stay as they are.

TASK-2: Change `UserRegisteredNotification` into a daily summary: it takes `int $count` (and, if useful, the window in hours) as a constructor scalar, and `toNtfy()` returns an English title such as "Daily registrations" and a message such as "10 users registered in the last 24 hours" (with "1 user" in the singular). Keep `ShouldQueue`, `$tries`, `$timeout` and the why-comment. Renaming the class (e.g. `DailyRegistrationsNotification`) is optional; if it's renamed, update every reference.
  files: app/Api/Users/Notifications/UserRegisteredNotification.php
  depends: none
  risk: low — the class has no other callers besides AuthService (TASK-1) and the new command (TASK-3).

TASK-3: Add the artisan command `app:users:report-registrations` (`app/Api/Users/Commands/ReportRegistrationsCommand.php`). It counts users with `created_at >= now()->subDay()` and sends the TASK-2 notification on demand to `config('services.ntfy.topics.registrations') ?: config('services.ntfy.topic')`. It returns SUCCESS whether or not ntfy is configured, because `AdminNotification::via()` already turns sending off, and it puts no user data (names or emails) in the message.
  files: app/Api/Users/Commands/ReportRegistrationsCommand.php (new)
  depends: TASK-2
  risk: low — read-only count plus the existing on-demand notification pattern (`SendTestNtfyCommand`, `TrackApiTraffic`).

TASK-4: Schedule the command daily in `routes/console.php` under the existing `Users` group: `Schedule::command(...)->dailyAt(8)` (see open question 2).
  files: routes/console.php
  depends: TASK-3
  risk: low — one added line in the existing pattern.

TASK-5: Update the tests. In `AuthTest.php`, replace the `POST /v1/auth/register ntfy announcement` describe block with one test that asserts registration sends no ntfy HTTP request even when ntfy is configured. In `app/Api/Users/Tests/CommandTest.php`, add tests for the new command with `Http::fake()` and frozen time:
- counts only users created in the last 24 hours (one older user is excluded), and the message contains the count
- sends to the registrations topic when set, and to the default topic otherwise
- sends nothing when ntfy isn't configured, and still succeeds
- zero-count behaviour, per open question 1
- the message contains no names or emails

The tests check what is actually sent, and the singular wording only if TASK-2 implements it.
  files: app/Api/Users/Tests/AuthTest.php, app/Api/Users/Tests/CommandTest.php
  depends: TASK-1, TASK-2, TASK-3
  risk: low — the existing tests show the Http::fake/config pattern; the developer must freeze time so the 24 h boundary isn't flaky.

TASK-6: Run `composer test` and `composer analyse` and make sure both exit 0, and grep for any remaining reference to the removed per-registration behaviour (`UserRegisteredNotification` if it was renamed, `'New user registered'`).
  files: none (verification)
  depends: TASK-1 to TASK-5
  risk: low — verification only.

## Not in scope

- Changes to `NtfyChannel`, `AdminNotification`, the dev-request or traffic alerts, or the ntfy config keys.
- Lang files: the message is an operator alert and stays English (STANDARDS Decisions).
- No migrations, no new dependencies, no DAV or `pgsql` changes.
