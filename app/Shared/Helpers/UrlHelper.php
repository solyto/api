<?php

namespace App\Shared\Helpers;

use Illuminate\Support\Str;

class UrlHelper
{
    public static function extractUrl(string $text): ?string
    {
        preg_match('/https?:\/\/[^\s<>"\']+/', $text, $matches);

        return $matches[0] ?? null;
    }

    public static function hasUrl(string $text): bool
    {
        return Str::contains($text, ['https://', 'http://', 'www.']);
    }
}
