<?php

namespace App\Api\Libraries\Enums;

enum MovieServiceEnum: string
{
    case IMDB = 'imdb';
    case TMDB = 'tmdb';

    public function baseUrl(): string
    {
        return match($this) {
            self::IMDB => 'imdb.com',
            self::TMDB => 'themoviedb.org',
        };
    }
}
