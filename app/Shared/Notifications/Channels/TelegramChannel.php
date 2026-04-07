<?php

namespace App\Shared\Notifications\Channels;

use App\Api\Users\Models\User;
use App\Shared\Services\TelegramBotService;
use Illuminate\Notifications\Notification;

class TelegramChannel
{
    public function send(User $notifiable, Notification $notification): void
    {
        $connection = $notifiable->telegramConnection;

        if (!$connection || !$connection->is_confirmed || !$connection->chat_id) {
            return;
        }

        $message = $notification->toTelegram($notifiable);

        if (!$message instanceof TelegramMessage) {
            return;
        }

        $telegramService = new TelegramBotService(
            config('telegram.bots.solyto.telegram_token')
        );

        $telegramService->sendText($connection->chat_id, $message->getContent());
    }
}
