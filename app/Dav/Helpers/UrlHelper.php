<?php

namespace App\Dav\Helpers;

class UrlHelper
{
    public static function getScheme(string $url): ?string
    {
        return parse_url($url, PHP_URL_SCHEME) ?? null;
    }

    public static function getHost(string $url): ?string
    {
        return parse_url($url, PHP_URL_HOST) ?? null;
    }

    public static function getBaseUrl(string $url): ?string
    {
        $parsed = parse_url($url);

        if (!$parsed) {
            return null;
        }

        return $parsed['scheme'] . '://' . $parsed['host'];
    }
}
