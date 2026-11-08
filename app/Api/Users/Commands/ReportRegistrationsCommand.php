<?php

namespace App\Api\Users\Commands;

use App\Api\Users\Models\User;
use App\Api\Users\Notifications\DailyRegistrationsNotification;
use App\Shared\Notifications\Channels\NtfyChannel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class ReportRegistrationsCommand extends Command
{
    protected $signature = 'app:users:report-registrations';

    protected $description = 'Send the number of registrations in the last 24 hours to ntfy';

    public function handle(): int
    {
        $count = User::where('created_at', '>=', now()->subDay())->count();

        Notification::route(NtfyChannel::class, config('services.ntfy.topics.registrations') ?: config('services.ntfy.topic'))
            ->notify(new DailyRegistrationsNotification($count));

        return self::SUCCESS;
    }
}
