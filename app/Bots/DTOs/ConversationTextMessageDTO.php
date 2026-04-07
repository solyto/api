<?php

namespace App\Bots\DTOs;

use App\Bots\Enums\ConversationStateRoleEnum;

class ConversationTextMessageDTO
{
    public function __construct(
        public ConversationStateRoleEnum $role,
        public string $text,
        public \DateTime $dateTime
    ) {}

    public function getRole(): ConversationStateRoleEnum
    {
        return $this->role;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getDateTime(): \DateTime
    {
        return $this->dateTime;
    }
}
