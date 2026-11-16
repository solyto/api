# Report: One bad timezone stops everyone's daily reminders, and the reminders are sent synchronously

date: 2026-11-16
read: false
branch: `main`, tree at `3527993`
scope: `SendDailyDayRemindersCommand` and `SendDailyCheckInRemindersCommand` (`app/Api/Users/Commands/`), `BaseNotification::via`, `UpdateTimezoneRequest`. Written for the maintainer. No application code, config or migrations were changed. Two throwaway probe tests were run and then deleted.

## Summary

1. **Security / reliability (confirmed by probe).** `PUT /api/v1/users/me/settings/timezone` accepts any string (`UpdateTimezoneRequest`: `required|string`). Both reminder commands call `Carbon::now($timezone)` **outside** their per-user `try` (`SendDailyDayRemindersCommand.php:35`, `SendDailyCheckInRemindersCommand.php:33`). One user who stores `Not/AZone` throws `InvalidTimeZoneException` in that loop. The command stops, and **every user after them in the loop gets no reminder**. This repeats every hour until the value is fixed. Any logged-in account can do this with one request. It can happen by accident too, e.g. a client that sends a timezone name the server's tzdata doesn't know.
2. **Performance.** No notification extends `ShouldQueue` (`BaseNotification` uses `Queueable` but doesn't implement the interface). So the hourly scheduler process does everything inline, one user after another: the todo query, the calendar read (for **external** calendars that's a remote CalDAV request with a 5 s timeout, `config/calendars.php:21`), the DB insert, the Mailgun HTTP call, one web-push HTTP call per subscription, and the Telegram call. At 07:00 Europe/Berlin, which is probably most users, this all lands in one serial run. Every other hour the command still loads all opted-in users with their settings, only to drop them in PHP.

## Measured

### Bad timezone (probe, SQLite in-memory, `Notification::fake()`)

User A saves `Not/AZone` through the real endpoint (200 OK). User B is a normal UTC user. The command runs at 07:00 UTC:

```
THROWN: Carbon\Exceptions\InvalidTimeZoneException: Unknown or bad timezone (Not/AZone)
B notified: 0
```

The same timezone is read without a guard in about 15 other places (`WidgetService`, `CalendarService::listToday*`, `CalendarController:551,654`, `EventDTO`, `SolytoBot`, `IntegrationGateway`). As far as I can tell, those only use the **requesting** user's own settings, so they would only break that user's own requests. Only the two commands affect other users.

### Reminder cost (probe: 40 opted-in UTC users, mail + UI + push enabled, `Queue::fake()`)

| run at | MariaDB queries | jobs queued | wall time (local, no network) |
|---|---|---|---|
| 08:00 (nobody due) | 3 (all settings + users + user_settings) | 0 | 8 ms |
| 07:00 (all 40 due) | **203** = 3 + 5 per user | **0** | 598 ms |

Per user: `todos`, `external_calendars`, `user_notification_settings` (lazy, in `via()`, even though the command just loaded that row), `push_subscriptions`, `insert notifications`. `queued=0` confirms that mail, push and Telegram all run inside the command process. Locally the mail transport is the array driver, so the 598 ms has **no network in it**. In production, add per user one Mailgun round trip, one round trip per push subscription, maybe Telegram, and for users with external calendars up to 5 s per unreachable remote. That last case is mitigated by the 5-minute "unavailable" cache, but only after the first failure.

Side effects of the serial run:
- The user at position N gets their "7:00" reminder after N × (sum of those round trips). One slow remote calendar or a Mailgun hiccup delays everyone after it.
- `if ($now->hour !== 7)` is evaluated per user while the loop is running. If a run ever takes past 08:00, the remaining users are skipped for that day. That's unlikely at today's scale, but the check gets weaker as the user base grows.

## Suggestion

In order of value per effort:

1. **Validate the timezone on write.** In `UpdateTimezoneRequest`, replace `string` with a rule that accepts exactly what the runtime accepts, e.g. a closure that tries `new \DateTimeZone($value)`. Don't use a list rule (`timezone:all` / `all_with_bc`). In this container's PHP, `Etc/GMT+1` is only in the BC list, and legacy names such as `Asia/Calcutta` / `Europe/Kiev`, which some browsers still report, are rejected by both the list **and** `new DateTimeZone`. So the parse check is the one that matches what the commands do later. Add a test for the 422.
2. **Contain the failure in the commands.** Move the `Carbon::now($timezone)` line inside the existing `try` in both commands, or fall back to `UTC` and `report()` it. The commands' own comment already states the goal ("one user's failure … must not cost everyone after them"), but the timezone line is outside the guard. Add a test: one user with a bad stored timezone, and a second user still gets the reminder. Steps 1 and 2 together are a small change.
3. **Check existing data** before or alongside step 1: `SELECT timezone, COUNT(*) FROM user_settings GROUP BY timezone`, and look for values `new DateTimeZone` rejects. Any such value is breaking reminders in production **today**, for whoever comes after it in the loop.
4. **Queue the reminder notifications.** Make `DailyDayReminderNotification` and `DailyCheckInReminderNotification` implement `ShouldQueue`. Start with just these two, not `BaseNotification`, so other notifications don't change behaviour as a side effect. Laravel then queues one job per channel on the existing Redis queue, and mail, push or Telegram failures get retries instead of a `report()`. `Notification` already uses `SerializesModels`, so the `todos` Eloquent collection serializes as IDs. The `events` collection of `EventDTO`s serializes as plain objects. Existing tests use `Notification::fake()` and keep passing. The calendar read stays in the command because it is per-user data collection, but it is now the only slow part left.
5. **Optional, cheaper hourly scan.** Work out which timezones are at 07:00 right now (`DateTimeZone::listIdentifiers()` filtered by `Carbon::now($tz)->hour === 7`, about 400 cheap checks). Then filter in SQL with `whereHas('user.settings', fn ($q) => $q->whereIn('timezone', $tzs))`. That removes the 23-of-24-hours full load. Also call `$user->setRelation('notificationSettings', $setting)` before `notify()` to save the lazy query in `via()`. Only worth doing once step 4 is in.

Steps 1–3 fix a cross-user bug that anyone can trigger, and should go first. Step 4 is the performance change.

## Open questions

- Does production `user_settings.timezone` already contain values `new DateTimeZone` rejects (see step 3)?
- Which timezone strings does the frontend send? If it can send legacy aliases (`Asia/Calcutta`), should the API map them to canonical names rather than reject them?
- Is a queue worker always running wherever the scheduler runs (the self-hosted setup included)? Step 4 makes reminders depend on it.
