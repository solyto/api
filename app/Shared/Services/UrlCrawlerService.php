<?php

namespace App\Shared\Services;

use Illuminate\Support\Facades\Http;

class UrlCrawlerService
{
    public function fetchTitle(string $url): string
    {
        try {
            $response = Http::timeout(3)->get($url);

            if (!$response->successful()) {
                return $url;
            }

            if (!preg_match('/<title[^>]*>([^<]+)<\/title>/i', $response->body(), $matches)) {
                return $url;
            }

            return trim($matches[1]);
        } catch (\Throwable) {
            return $url;
        }
    }
}
