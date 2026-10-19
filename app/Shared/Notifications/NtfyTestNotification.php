<?php

namespace App\Shared\Notifications;

use App\Shared\Notifications\Channels\NtfyMessage;

// Not queued: the test command should publish right away, without a worker.
class NtfyTestNotification extends AdminNotification
{
    public function toNtfy(object $notifiable): NtfyMessage
    {
        return NtfyMessage::create()
            ->title('Test notification')
            ->message('This is a test notification to verify ntfy is working.');
    }
}
