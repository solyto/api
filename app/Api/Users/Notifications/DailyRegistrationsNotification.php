<?php

namespace App\Api\Users\Notifications;

use App\Shared\Notifications\AdminNotification;
use App\Shared\Notifications\Channels\NtfyMessage;
use Illuminate\Contracts\Queue\ShouldQueue;

class DailyRegistrationsNotification extends AdminNotification implements ShouldQueue
{
    // NtfyChannel logs failures instead of throwing, so a retry would never
    // fire; the timeout covers the channel's 5 s HTTP timeout and stays below
    // the queue's 360 s retry_after.
    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(
        public readonly int $count,
    ) {}

    public function toNtfy(object $notifiable): NtfyMessage
    {
        $users = $this->count === 1 ? 'user' : 'users';

        return NtfyMessage::create()
            ->title('Daily registrations')
            ->message("{$this->count} {$users} registered in the last 24 hours.");
    }
}
