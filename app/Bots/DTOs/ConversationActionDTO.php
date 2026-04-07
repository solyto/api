<?php

namespace App\Bots\DTOs;

use App\Bots\Enums\ConversationStateRoleEnum;

class ConversationActionDTO
{
    public function __construct(
        public string $name,
        public \DateTime $dateTime,
        public array $data = [],
        public ConversationStateRoleEnum $role = ConversationStateRoleEnum::SYSTEM
    ) {}

    public function getRole(): string
    {
        return $this->role;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getDateTime(): \DateTime
    {
        return $this->dateTime;
    }
}
