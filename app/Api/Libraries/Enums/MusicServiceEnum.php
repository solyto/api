<?php

namespace App\Api\Libraries\Enums;

enum MusicServiceEnum: string
{
    case DISCOGS = 'discogs';
    case DEEZER = 'deezer';

    public function baseUrl(): string
    {
        return match($this) {
            self::DISCOGS => 'discogs.com',
            self::DEEZER => 'deezer.com',
        };
    }
}
