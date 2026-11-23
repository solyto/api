<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Feeds
 */
Schedule::job(\App\Api\Feeds\Jobs\SyncFeeds::class)->hourly();
Schedule::job(\App\Api\Feeds\Jobs\DeleteOldFeedItems::class)->dailyAt(2);

/*
 * Dev Requests
 */
Schedule::job(\App\Api\DevRequests\Jobs\DeleteOldDevRequests::class)->dailyAt(2);

/*
 * Libraries
 */
Schedule::job(\App\Api\Libraries\Jobs\GrabMusicReleases::class)->dailyAt(5);
Schedule::job(\App\Api\Libraries\Jobs\GrabBookReleases::class)->weekly();
// Temporarily deactivated: movie release notifications are not working
// properly (job elect / deactivate-movie-release-notifications). Re-enable
// by uncommenting once fixed.
// Schedule::job(\App\Api\Libraries\Jobs\GrabMovieReleases::class)->weekly();
Schedule::job(\App\Api\Libraries\Jobs\ScaleCovers::class)->dailyAt(3);

/*
 * Users
 */
Schedule::job(\App\Api\Users\Jobs\DeleteOldFriendRequests::class)->dailyAt(2);
Schedule::command(\App\Api\Users\Commands\ReportRegistrationsCommand::class)->dailyAt(8);

/*
 * Clipboard
 */
Schedule::job(\App\Api\Clipboard\Jobs\DeleteOverdueClipboardEntries::class)->dailyAt(2);

/*
 * Daily Reminders
 */
Schedule::command(\App\Api\Users\Commands\SendDailyDayRemindersCommand::class)->hourly();
Schedule::command(\App\Api\Users\Commands\SendDailyCheckInRemindersCommand::class)->hourly();
// The lock expires after an hour (default: a day), so a crashed run doesn't hold up the next hours' alerts.
Schedule::command(\App\Api\Alerts\Commands\SendAlertsCommand::class)->hourly()->withoutOverlapping(60);

/*
 * Export
 */
Schedule::job(\App\Api\Export\Jobs\DeleteExpiredExports::class)->dailyAt(3);

/*
 * Cache: drop expired entries from the user_data tag sets (UserCacheService)
 */
Schedule::command('cache:prune-stale-tags user_data')->hourly();

/*
 * Pulse: queue length for the Queue Load card (App\Shared\Pulse)
 */
Schedule::call(new \App\Shared\Pulse\RecordQueueSize())->everyMinute()->name('pulse-queue-size')->withoutOverlapping();
