<?php

namespace App\Api\Users\Commands;

use App\Api\Calendars\Models\CalendarEntry;
use App\Api\Todos\Models\Todo;
use App\Api\Users\Models\UserNotificationSettings;
use App\Api\Users\Notifications\DailyDayReminderNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendDailyDayRemindersCommand extends Command
{
    protected $signature = 'app:send-daily-day-reminders';

    protected $description = 'Send daily day-ahead reminders via the notification system';

    public function handle(): void
    {
        $settings = UserNotificationSettings::where('daily_day_reminder_ui', true)
            ->orWhere('daily_day_reminder_email', true)
            ->orWhere('daily_day_reminder_push', true)
            ->orWhere('daily_day_reminder_telegram', true)
            ->with('user.settings')
            ->get();

        foreach ($settings as $setting) {
            $user = $setting->user;

            if (!$user) {
                continue;
            }

            $timezone = $user->settings->timezone ?? 'UTC';
            $now = Carbon::now($timezone);

            if ($now->hour !== 7) {
                continue;
            }

            $todos = Todo::forUser($user->id)
                ->where('is_completed', false)
                ->where('due_at', '<=', today($timezone))
                ->get();

            $dayStart = Carbon::now($timezone)->startOfDay()->timestamp;
            $dayEnd = Carbon::now($timezone)->endOfDay()->timestamp;

            $events = CalendarEntry::forUser($user->id)
                ->whereBetween('start_date', [$dayStart, $dayEnd])
                ->get();

            $user->notify(new DailyDayReminderNotification(
                date: $now->toDateString(),
                todos: $todos,
                events: $events
            ));

            $this->info("Sent daily day reminder to {$user->name}");
        }
    }
}
