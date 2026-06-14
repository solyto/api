<?php

namespace App\Api\Libraries\Enums;

enum RecipeServiceEnum: string
{
    case CHEFKOCH = 'chefkoch';

    public function baseUrl(): string
    {
        return match($this) {
            self::CHEFKOCH => 'chefkoch.de',
        };
    }
}
