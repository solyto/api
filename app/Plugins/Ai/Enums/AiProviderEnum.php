<?php

namespace App\Plugins\Ai\Enums;

enum AiProviderEnum: string
{
    case OPENAI = 'openai';
    case OPENROUTER = 'openrouter';
    case MISTRAL = 'mistral';

    public function baseUrl(): string
    {
        return match ($this) {
            self::OPENAI => 'https://api.openai.com/v1',
            self::OPENROUTER => 'https://openrouter.ai/api/v1',
            self::MISTRAL => 'https://api.mistral.ai/v1',
        };
    }
}
