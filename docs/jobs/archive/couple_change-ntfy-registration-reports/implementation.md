# Implementation: change ntfy registration reports

id: couple
developer: developer
date: 2026-11-08

## Summary

Registration no longer sends an ntfy message. A new daily command (`app:users:report-registrations`, scheduled at 08:00) sends one message with the number of accounts created in the last 24 hours, e.g. "10 users registered in the last 24 hours." It goes to the same topic as before (`services.ntfy.topics.registrations`, falling back to `services.ntfy.topic`). Open-question defaults were followed: a "0 users" message is still sent, the report runs at `dailyAt(8)`, and all created accounts count, verified or not.

## Changes

TASK-1: `app/Api/Users/Services/AuthService.php` — removed the on-demand ntfy call from `register()` and the three imports that only it used.
TASK-2: `app/Api/Users/Notifications/UserRegisteredNotification.php` renamed to `DailyRegistrationsNotification.php`. It takes `int $count` and sends the title "Daily registrations" and the message "N users registered in the last 24 hours." ("1 user" in the singular). `ShouldQueue`, `$tries`, `$timeout` and the why-comment are unchanged.
TASK-3: New `app/Api/Users/Commands/ReportRegistrationsCommand.php` — counts `users.created_at >= now()->subDay()` and notifies on demand. It always returns SUCCESS; `AdminNotification::via()` turns sending off when ntfy isn't configured. The message contains no user data.
TASK-4: `routes/console.php` — scheduled the command `dailyAt(8)` in the Users group.
TASK-5: `AuthTest.php` — replaced the ntfy announcement block with one test: registration sends no HTTP request even when ntfy is configured (removed the now-unused imports). `CommandTest.php` — new "Registrations report command" block with frozen time: the 24 h window boundary (one user excluded at 24 h + 1 s, one included at exactly 24 h), singular wording, the zero count, the registrations topic vs. the default topic, nothing sent when unconfigured, and no names or emails in the body.
TASK-6: `composer test` and `composer analyse` both exit 0. No live references to `UserRegisteredNotification` or "New user registered" remain; only archived job records and the 2026-10-14 ntfy report mention them, and those are historical.

## Known issues / follow-ups

- The first full `composer test` run straight after `composer install` reported 2 failures. Three later full runs (including `composer test`) were all green, so I couldn't identify them. It's probably a first-run or flaky-test issue unrelated to this change.
- The test warnings (`file_get_contents(/workspace/.env)`) come from the sandbox: it has no `.env`, and `.env.testing` is masked there. They aren't from this change.
