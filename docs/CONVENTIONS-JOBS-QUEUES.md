# Conventions — Jobs, Queues & Scheduling

Reference files:
`app/Api/Calendars/Jobs/RefreshCalendarCache.php`,
`app/Api/Feeds/Jobs/SyncFeeds.php`, `routes/console.php`,
`bootstrap/app.php`, `app/Api/Calendars/Notifications/CalendarShareNotification.php`.

## When to use a Job

Heavy or slow work never runs in the request cycle. Dispatch a queued Job when
something is:

- periodic maintenance or ingestion (feed sync, release grabs, retention
  cleanup);
- slow enough to make an API call drag (calendar cache refresh, imports,
  exports, remote API calls);
- a multi-step side effect that should not block the response.

Jobs live in the owning module's `Jobs/` folder
(`app/Api/<Module>/Jobs/`) — same module rule as everything else.

## Job shape

```php
// app/Api/Feeds/Jobs/SyncFeeds.php
class SyncFeeds implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct() {}

    public function handle(): void
    {
        // dispatch one SyncFeed job per feed...
    }
}
```

- `implements ShouldQueue`; the three traits (`Queueable`, `Dispatchable`,
  `InteractsWithQueue`).
- The queue is Redis (the default queue connection — see `config/queue.php`).
- **Constructor only carries identifiers** (a `User`, ids), not fetched data —
  the job re-reads state in `handle()`. `RefreshCalendarCache` takes the
  `User`, `SyncFeeds` takes nothing and scans the table.
- `handle()` uses the same service layer as the API: `RefreshCalendarCache`
  calls `app(CalendarService::class)->list(...)`; a job never re-implements
  module logic.
- Tuning is per-job properties (`$tries`, `$timeout`). `SyncFeeds` sets
  `tries = 1` deliberately so one poisoned feed does not retry forever.
- **One job dispatches one unit of work.** `SyncFeeds` only enqueues one
  `SyncFeed` per feed ("so that a single slow or failing feed cannot starve the
  others") — the fan-out happens inside the first job, not the web request.

## Scheduling

Schedules are declared in `routes/console.php` — not in a class, not in a
provider. The file is grouped by module with a comment header:

```php
// routes/console.php
Schedule::job(\App\Api\Feeds\Jobs\SyncFeeds::class)->hourly();
Schedule::job(\App\Api\Feeds\Jobs\DeleteOldFeedItems::class)->dailyAt(2);
Schedule::command(\App\Api\Users\Commands\SendDailyDayRemindersCommand::class)->hourly();
```

- Use `Schedule::job(JobClass::class)` for jobs, `Schedule::command(...)` for
  commands.
- Pick the frequency that fits (`hourly()`, `dailyAt(2)`,
  `weekly()`) and keep the existing grouping comments.
- Deactivated schedules stay as commented-out lines with a reason
  (see `GrabMovieReleases`).

## Commands

Hand-written artisan commands live under `app/Api/<Module>/Commands/`
(`app/Api/Users/Commands/SendDailyDayRemindersCommand.php`,
`app/Api/Contacts/Commands/ScaleContactPhotos.php`, ...) and are registered in
`bootstrap/app.php` `->withCommands([...])` per module directory — the paths
there are the registration list, so **new command folders must be added to that
array**:

```php
// bootstrap/app.php
->withCommands([
    __DIR__.'/../app/Api/Contacts/Commands',
    __DIR__.'/../app/Api/Users/Commands',
    // ...
])
```

Do not put commands in `app/Console/Commands` (the stock Laravel location is
not used by this repo).

## User-facing delivery: Notifications

User-facing messages (email, web push, database, Telegram) go through Laravel
Notifications, co-located in `app/Api/<Module>/Notifications/`
(`app/Api/Calendars/Notifications/CalendarShareNotification.php`).

Notification classes extend `App\Shared\Notifications\BaseNotification` and
implement the channels they support (`toDatabase`, `toWebPush`, `toMail`,
Telegram via `App\Shared\Notifications\Channels\TelegramMessage`):

```php
class CalendarShareNotification extends BaseNotification
{
    protected function getNotificationType(): string
    {
        return 'calendar_share';
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->withLocale($notifiable, fn () => [
            'title' => __('notifications.calendar_share_title'),
            'body'  => __('notifications.calendar_share_body', ['sender' => $this->senderName]),
            'link'  => '/calendar?invitations',
        ]);
    }
}
```

Copy in the in-app/database channel is localized (`resources/lang/*`) and the
`databaseType()` string is the stable client-side identifier. If a feature
needs to notify users, add a Notification here — do not send mails/pushes
inline from a controller or job.

## Rules of thumb

- SYNC: async work → Job; periodic → schedule in `routes/console.php`; CLI →
  module Command registered in `bootstrap/app.php`.
- Jobs dispatch other jobs; they never call the HTTP layer.
- Jobs re-read state; they never trust constructor payloads as fresh.
- Keep `$tries`/`$timeout` explicit when the default retry behaviour is wrong
  for the job.

## Checklist

- [ ] Job in `app/Api/<Module>/Jobs/`, `implements ShouldQueue`, state re-read in `handle()`.
- [ ] `handle()` delegates to module/`app/Shared` services.
- [ ] Schedule registered in `routes/console.php` with the module comment group.
- [ ] New command folder added to `->withCommands([...])` in `bootstrap/app.php`.
- [ ] User-facing messages are Notifications in `app/Api/<Module>/Notifications/`.