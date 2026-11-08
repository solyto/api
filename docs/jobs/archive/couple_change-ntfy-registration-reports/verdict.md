# Verdict: change ntfy registration reports

id: couple
reviewer: reviewer
date: 2026-11-08

## Review

TASK-1: PASS
notes: `AuthService::register` no longer sends the ntfy call. The three imports that only it used (`UserRegisteredNotification`, `NtfyChannel`, `Notification`) are removed. The verification mail and user creation are unchanged.

TASK-2: PASS
notes: The class is renamed to `DailyRegistrationsNotification`, with `int $count` passed as a readonly constructor scalar. Title is "Daily registrations" and the message is "N users registered in the last 24 hours." with a singular form for 1. `ShouldQueue`, `$tries`, `$timeout` and the why-comment are all kept. No references to the old class or the "New user registered" text remain in app/, routes/, tests/ or config/.

TASK-3: PASS
notes: `ReportRegistrationsCommand` (`app:users:report-registrations`) counts users with `created_at >= now()->subDay()`. It routes the notification on demand to the registrations topic, falling back to the default topic, and always returns SUCCESS. The message contains no user data.

TASK-4: PASS
notes: `routes/console.php` adds `Schedule::command(ReportRegistrationsCommand::class)->dailyAt(8)` in the Users group, following the existing `Schedule::command` style.

TASK-5: PASS
notes: In AuthTest, the old block is replaced by one test: registration with ntfy configured sends nothing. The imports it no longer needed are removed. CommandTest freezes time and covers:
- the 24 h boundary (exactly 24 h is included, 24 h + 1 s is excluded)
- the exact message and title
- the singular form
- the zero count
- the registrations topic vs. the default topic
- the case where ntfy isn't configured
- the absence of personal data

TASK-6: PASS
notes: I re-ran both checks myself. `composer test` exits 0 (4856 assertions, no failures) and `composer analyse` exits 0 ("No errors"). The warnings come from the sandbox (`/workspace/.env` is missing), not from this change.

Scope: the diff contains only the files in the plan plus the job docs. Nothing changed outside the plan. The open-question defaults (send on zero, `dailyAt(8)`, count all accounts) were followed and documented.

## Security

none (not run). No user data goes into the alert, and the work is a read-only count.

## Overall

APPROVED. Nothing needs to change before merge.
