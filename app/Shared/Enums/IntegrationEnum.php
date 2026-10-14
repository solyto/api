<?php

namespace App\Shared\Enums;

enum IntegrationEnum: string
{
    case HARDCOVER = 'hardcover';
    case TMDB = 'tmdb';
    case DISCOGS = 'discogs';
    case BGG = 'bgg';
    case SPOTIFY = 'spotify';
    case TELEGRAM = 'telegram';
    case NTFY = 'ntfy';

    public function isConfigured(): bool
    {
        return match($this) {
            self::HARDCOVER => filled(config('services.hardcover.api_key')),
            self::TMDB => filled(config('services.tmdb.access_token')),
            self::DISCOGS => filled(config('services.discogs.access_token')),
            self::BGG => filled(config('services.bgg.api_key')),
            self::SPOTIFY => filled(config('services.spotify.client_id')) && filled(config('services.spotify.client_secret')),
            self::TELEGRAM => filled(config('telegram.bots.solyto.telegram_token')) && filled(config('telegram.bots.solyto.webhook_token')),
            self::NTFY => filled(config('services.ntfy.url')) && filled(config('services.ntfy.topic')),
        };
    }
}
