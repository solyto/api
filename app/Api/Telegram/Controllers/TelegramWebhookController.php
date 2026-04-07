<?php

namespace App\Api\Telegram\Controllers;

use App\Bots\SolytoBot;
use Illuminate\Http\Request;

class TelegramWebhookController
{
    public function solyto(Request $request, string $token): void
    {
        abort_if($token !== config('telegram.bots.solyto.webhook_token'), 401);
        $service = app(SolytoBot::class);
        $service->handleWebhook($request);
    }
}
