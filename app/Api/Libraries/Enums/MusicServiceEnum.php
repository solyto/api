<?php

namespace App\Api\Libraries\Enums;

enum MusicServiceEnum: string
{
    case DISCOGS = 'discogs';
    case DEEZER = 'deezer';
    case SPOTIFY = 'spotify';

    public function baseUrl(): string
    {
        return match($this) {
            self::DISCOGS => 'discogs.com',
            self::DEEZER => 'deezer.com',
            self::SPOTIFY => 'open.spotify.com',
        };
    }
}
