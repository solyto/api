<?php

namespace App\Bots\State;

use App\Bots\DTOs\ConversationActionDTO;
use App\Bots\DTOs\ConversationTextMessageDTO;
use App\Bots\Enums\ConversationStateRoleEnum;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class ConversationState
{
    private array $events = [];

    private function __construct(
        private readonly string $identifier,
    ) {}

    public static function loadOrMake(string $identifier): ConversationState
    {
        $fromCache = Cache::store('conversation_state')->get($identifier);

        if ($fromCache) {
            return unserialize($fromCache);
        }

        return new ConversationState($identifier);
    }

    public function store(): bool
    {
        return Cache::store('conversation_state')->put($this->identifier, serialize($this), 600);
    }

    public function destroy(): bool
    {
        return Cache::store('conversation_state')->forget($this->identifier);
    }

    public function addUserTextEvent(string $message): ConversationState
    {
        array_unshift($this->events, new ConversationTextMessageDTO(
            ConversationStateRoleEnum::USER,
            $message,
            Carbon::now()
        ));

        return $this;
    }

    public function addActionEvent(string $action, array $data = []): ConversationState
    {
        array_unshift($this->events, new ConversationActionDTO(
            $action,
            Carbon::now(),
            $data
        ));

        return $this;
    }

    public function getLastUserMessage(): ?ConversationTextMessageDTO
    {
        return array_find($this->events, function ($event) {
            return $event instanceof ConversationTextMessageDTO && $event->getRole() === ConversationStateRoleEnum::USER;
        }) ?? null;
    }

    public function getLastActionEvent(): ?ConversationActionDTO
    {
        return array_find($this->events, function ($event) {
            return $event instanceof ConversationActionDTO;
        }) ?? null;
    }
}
