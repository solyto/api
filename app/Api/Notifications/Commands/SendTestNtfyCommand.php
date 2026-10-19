<?php

namespace App\Api\Notifications\Commands;

use App\Shared\Notifications\Channels\NtfyChannel;
use App\Shared\Notifications\NtfyTestNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class SendTestNtfyCommand extends Command
{
    protected $signature = 'app:notifications:test-ntfy {topic? : Topic to publish to (defaults to services.ntfy.topic)}';

    protected $description = 'Send a test notification to an ntfy topic';

    public function handle(): int
    {
        if (!filled(config('services.ntfy.url'))) {
            $this->error('ntfy is not configured (services.ntfy.url is empty).');
            return self::FAILURE;
        }

        $topic = $this->argument('topic') ?: config('services.ntfy.topic');

        if (!filled($topic)) {
            $this->error('No topic given and services.ntfy.topic is empty.');
            return self::FAILURE;
        }

        Notification::route(NtfyChannel::class, $topic)->notify(new NtfyTestNotification());

        // NtfyChannel logs failures instead of throwing, so this cannot confirm delivery.
        $this->info("Test notification published to topic {$topic}. If it does not arrive, check the log for \"ntfy publish failed\".");

        return self::SUCCESS;
    }
}
