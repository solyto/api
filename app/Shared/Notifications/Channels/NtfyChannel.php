<?php

namespace App\Shared\Notifications\Channels;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NtfyChannel
{
    private const int TIMEOUT_SECONDS = 5;

    public function send(object $notifiable, Notification $notification): void
    {
        $url = config('services.ntfy.url');
        $topic = $notifiable->routeNotificationFor(NtfyChannel::class, $notification);

        if (!filled($url) || !filled($topic) || !method_exists($notification, 'toNtfy')) {
            return;
        }

        $message = $notification->toNtfy($notifiable);

        if (!$message instanceof NtfyMessage) {
            return;
        }

        $request = Http::timeout(self::TIMEOUT_SECONDS);

        $token = config('services.ntfy.token');
        if (filled($token)) {
            $request = $request->withToken($token);
        }

        $host = parse_url($url, PHP_URL_HOST);

        try {
            $response = $request->post($url, $message->toArray($topic));
        } catch (ConnectionException) {
            Log::warning('ntfy publish failed: connection error', ['host' => $host]);
            return;
        }

        if (!$response->successful()) {
            Log::warning('ntfy publish failed', ['status' => $response->status(), 'host' => $host]);
        }
    }
}
