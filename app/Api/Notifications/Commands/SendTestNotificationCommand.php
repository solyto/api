<?php

namespace App\Api\Notifications\Commands;

use App\Api\Users\Models\User;
use App\Shared\Notifications\TestNotification;
use Illuminate\Console\Command;

class SendTestNotificationCommand extends Command
{
    protected $signature = 'app:send-test-notification {email=leomuck@posteo.de}';

    protected $description = 'Send a test notification on all channels to a user by email';

    public function handle(): void
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("User with email {$email} not found.");
            return;
        }

        $user->notify(new TestNotification());

        $this->info("Test notification sent to {$user->name} ({$email}).");
    }
}
