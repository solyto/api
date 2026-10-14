<?php

namespace App\Shared\Notifications;

use App\Shared\Notifications\Channels\NtfyChannel;
use App\Shared\Notifications\Channels\NtfyMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

abstract class AdminNotification extends Notification
{
    use Queueable;

    abstract public function toNtfy(object $notifiable): NtfyMessage;

    public function via(object $notifiable): array
    {
        if (!filled(config('services.ntfy.url')) || !filled(config('services.ntfy.topic'))) {
            return [];
        }

        return [NtfyChannel::class];
    }
}
