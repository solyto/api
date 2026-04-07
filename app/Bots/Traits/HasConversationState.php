<?php

namespace App\Bots\Traits;

use App\Bots\State\ConversationState;

trait HasConversationState
{
    protected ConversationState $state;

    protected function initState(string $chatId, string $botName): void
    {
        $identifier = $chatId . '_' . $botName;
        $this->state = ConversationState::loadOrMake($identifier);
    }
}
