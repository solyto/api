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
Schedule::command(\App\Api\CheckIn\Commands\SendCheckInAlertsCommand::class)->everyMinute()->withoutOverlapping();

/*
 * Export
 */
Schedule::job(\App\Api\Export\Jobs\DeleteExpiredExports::class)->dailyAt(3);

/*
 * Pulse: queue length for the Queue Load card (App\Shared\Pulse)
 */
Schedule::call(function () {
    $connection = config('queue.default');
    $queue = config("queue.connections.{$connection}.queue", 'default');

    \App\Shared\Pulse\PulseMetrics::queueSize($queue, \Illuminate\Support\Facades\Queue::connection($connection)->size($queue));
})->everyMinute()->name('pulse-queue-size')->withoutOverlapping();
