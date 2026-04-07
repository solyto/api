<?php

namespace App\Bots\Traits;

trait HasBotSelector
{
    protected function selectBot(): ?array
    {
        $bots = config('telegram.bots');
        $this->info('Select a bot to register with Telegram Webhooks.');
        $bot = $this->choice('Bot', array_keys(config('telegram.bots')));

        return $bot ? array_merge(['name' => $bot], $bots[$bot]) : null;
    }
}
