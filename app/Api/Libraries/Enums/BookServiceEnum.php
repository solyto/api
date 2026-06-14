<?php

namespace App\Api\Libraries\Enums;

enum BookServiceEnum: string
{
    case HARDCOVER = 'hardcover';
    case GOODREADS = 'goodreads';

    public function baseUrl(): string
    {
        return match ($this) {
            self::HARDCOVER => 'hardcover.app',
            self::GOODREADS => 'goodreads.com',
        };
    }
}
