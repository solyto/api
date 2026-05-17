<?php

namespace App\Shared\Services;

use Illuminate\Support\Facades\Http;

class UrlCrawlerService
{
    public function fetchTitle(string $url): string
    {
        try {
            if ($this->isYouTubeUrl($url)) {
                return $this->fetchYouTubeTitle($url) ?? $url;
            }

            $html = $this->fetchHtml($url);

            return $this->extractOgTitle($html)
                ?? $this->extractHtmlTitle($html)
                ?? $url;
        } catch (\Throwable) {
            return $url;
        }
    }

    private function isYouTubeUrl(string $url): bool
    {
        return (bool) preg_match('/^https?:\/\/(www\.)?(youtube\.com|youtu\.be)\//i', $url);
    }

    private function fetchYouTubeTitle(string $url): ?string
    {
        $response = Http::timeout(3)->get('https://www.youtube.com/oembed', [
            'url' => $url,
            'format' => 'json',
        ]);

        return $response->successful() ? $response->json('title') : null;
    }

    private function fetchHtml(string $url): ?string
    {
        $response = Http::timeout(3)->get($url);

        return $response->successful() ? $response->body() : null;
    }

    private function extractOgTitle(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $patterns = [
            '/<meta[^>]+property=["\']og:title["\'][^>]+content=["\'](.*?)["\']/i',
            '/<meta[^>]+content=["\'](.*?)["\']\s+property=["\']og:title["\']/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                return $this->decodeTitle($matches[1]);
            }
        }

        return null;
    }

    private function extractHtmlTitle(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        if (!preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $matches)) {
            return null;
        }

        return $this->decodeTitle($matches[1]);
    }

    private function decodeTitle(string $title): string
    {
        return trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5));
    }
}
