<?php

namespace App\Api\Users\Notifications;

use App\Shared\Notifications\AdminNotification;
use App\Shared\Notifications\Channels\NtfyMessage;
use Illuminate\Contracts\Queue\ShouldQueue;

class UserRegisteredNotification extends AdminNotification implements ShouldQueue
{
    // NtfyChannel logs failures instead of throwing, so a retry would never
    // fire; the timeout covers the channel's 5 s HTTP timeout and stays below
    // the queue's 360 s retry_after.
    public int $tries = 1;
    public int $timeout = 30;

    public function toNtfy(object $notifiable): NtfyMessage
    {
        return NtfyMessage::create()
            ->title('New user registered')
            ->message('A new user account was created.');
    }
}
