<?php

namespace App\Api\Libraries\Enums;

enum GameServiceEnum: string
{
    case STEAM = 'steam';
    case BGG = 'bgg';

    public function baseUrl(): string
    {
        return match($this) {
            self::STEAM => 'store.steampowered.com',
            self::BGG => 'boardgamegeek.com',
        };
    }
}
