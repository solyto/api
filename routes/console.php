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
Schedule::job(\App\Api\Dev\Jobs\DeleteOldDevRequests::class)->dailyAt(2);

/*
 * Libraries
 */
Schedule::job(\App\Api\Libraries\Jobs\GrabMusicReleases::class)->dailyAt(5);
Schedule::job(\App\Api\Libraries\Jobs\GrabBookReleases::class)->weekly();
Schedule::job(\App\Api\Libraries\Jobs\ScaleCovers::class)->dailyAt(3);

/*
 * Users
 */
Schedule::job(\App\Api\Users\Jobs\DeleteOldFriendRequests::class)->dailyAt(2);

/*
 * Clipboard
 */
Schedule::job(\App\Api\Clipboard\Jobs\DeleteOverdueClipboardEntries::class)->dailyAt(2);

/*
 * Daily Reminders
 */
Schedule::command(\App\Api\Users\Commands\SendDailyDayRemindersCommand::class)->hourly();
Schedule::command(\App\Api\Users\Commands\SendDailyCheckInRemindersCommand::class)->hourly();

/*
 * Export
 */
Schedule::job(\App\Api\Export\Jobs\DeleteExpiredExports::class)->dailyAt(3);
