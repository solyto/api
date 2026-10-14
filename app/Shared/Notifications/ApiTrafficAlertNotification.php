<?php

namespace App\Shared\Notifications;

use App\Shared\Notifications\Channels\NtfyMessage;
use Illuminate\Contracts\Queue\ShouldQueue;

class ApiTrafficAlertNotification extends AdminNotification implements ShouldQueue
{
    // NtfyChannel logs failures instead of throwing, so a retry would never
    // fire; the timeout covers the channel's 5 s HTTP timeout and stays below
    // the queue's 360 s retry_after.
    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(
        private readonly string $userId,
        private readonly int $count,
    ) {}

    public function toNtfy(object $notifiable): NtfyMessage
    {
        return NtfyMessage::create()
            ->title('High API traffic')
            ->message("User {$this->userId} sent {$this->count} requests within one minute.");
    }
}
