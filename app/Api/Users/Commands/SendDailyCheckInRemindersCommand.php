<?php

namespace App\Api\Users\Commands;

use App\Api\Users\Models\UserNotificationSettings;
use App\Api\Users\Notifications\DailyCheckInReminderNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendDailyCheckInRemindersCommand extends Command
{
    protected $signature = 'app:send-daily-check-in-reminders';

    protected $description = 'Send daily check-in reminders via the notification system';

    public function handle(): void
    {
        $settings = UserNotificationSettings::where('daily_check_in_reminder_ui', true)
            ->orWhere('daily_check_in_reminder_email', true)
            ->orWhere('daily_check_in_reminder_push', true)
            ->orWhere('daily_check_in_reminder_telegram', true)
            ->with('user.settings')
            ->get();

        foreach ($settings as $setting) {
            $user = $setting->user;

            if (!$user) {
                continue;
            }

            $timezone = $user->settings->timezone ?? 'UTC';
            $now = Carbon::now($timezone);

            if ($now->hour !== 20) {
                continue;
            }

            $user->notify(new DailyCheckInReminderNotification(
                date: $now->toDateString()
            ));

            $this->info("Sent daily check-in reminder to {$user->name}");
        }
    }
}
