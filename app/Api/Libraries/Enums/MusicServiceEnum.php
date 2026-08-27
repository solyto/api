<?php

namespace App\Api\Libraries\Enums;

enum MusicServiceEnum: string
{
    case DEEZER = 'deezer';
    case DISCOGS = 'discogs';
    case SPOTIFY = 'spotify';

    public function baseUrl(): string
    {
        return match($this) {
            self::DISCOGS => 'discogs.com',
            self::DEEZER => 'deezer.com',
            self::SPOTIFY => 'open.spotify.com',
        };
    }

    public function isConfigured(): bool
    {
        return match ($this) {
            self::DEEZER => true,
            self::DISCOGS => ! empty(config('services.discogs.access_token')),
            self::SPOTIFY => ! empty(config('services.spotify.client_id')) && ! empty(config('services.spotify.client_secret')),
        };
    }
}
