<?php

namespace App\Api\Feeds\Services;

use Illuminate\Support\Facades\Http;
use SimplePie\SimplePie;

class FeedReader
{
    private SimplePie $pie;

    public function __construct(?SimplePie $pie = null)
    {
        $this->pie = $pie ?? new SimplePie;
        $this->pie->enable_cache(false);
    }

    public function getItems(string $url): array|bool
    {
        $this->pie->set_feed_url($url);
        $this->pie->init();

        $items = $this->pie->get_items();

        if (empty($items)) {
            return false;
        }

        return $items;
    }

    public function getFeedData(string $url): array|false
    {
        $this->pie->set_feed_url($url);
        $this->pie->init();

        $items = $this->pie->get_items();

        if (empty($items)) {
            return false;
        }

        return [
            'title' => $this->pie->get_title() ?: null,
            'items' => $items,
        ];
    }

    /**
     * Hosts that only serve tracking pixels. Their images are skipped even when
     * no size is given, so clients never load the tracker. VG Wort counts reads
     * for German publishers on per-server subdomains, hence the suffix match.
     */
    private const array TRACKER_HOSTS = [
        'cpx.golem.de',
        'met.vgwort.de',
        'pixel.wp.com',
        'stats.wordpress.com',
        'feeds.feedburner.com',
    ];

    public function extractImageUrl(\SimplePie\Item $item): ?string
    {
        $thumbnail = $item->get_item_tags('http://search.yahoo.com/mrss/', 'thumbnail');
        if (! empty($thumbnail[0]['attribs']['']['url'])) {
            $attribs = $thumbnail[0]['attribs'][''];
            if ($this->isUsableImage($attribs['url'], $attribs['width'] ?? null, $attribs['height'] ?? null)) {
                return $attribs['url'];
            }
        }

        $content = $item->get_item_tags('http://search.yahoo.com/mrss/', 'content');
        if (! empty($content)) {
            foreach ($content as $mediaContent) {
                $attribs = $mediaContent['attribs'][''] ?? [];
                $medium = $attribs['medium'] ?? '';
                $type = $attribs['type'] ?? '';
                if (($medium === 'image' || str_starts_with($type, 'image/'))
                    && ! empty($attribs['url'])
                    && $this->isUsableImage($attribs['url'], $attribs['width'] ?? null, $attribs['height'] ?? null)) {
                    return $attribs['url'];
                }
            }
        }

        $enclosures = $item->get_enclosures();
        if ($enclosures) {
            foreach ($enclosures as $enclosure) {
                if (($enclosure->get_medium() === 'image' || str_starts_with((string) $enclosure->get_type(), 'image/'))
                    && $enclosure->get_link()
                    && $this->isUsableImage($enclosure->get_link(), $enclosure->get_width(), $enclosure->get_height())) {
                    return $enclosure->get_link();
                }
            }
        }

        // get_content() is content:encoded (falling back to the description), so
        // the full article HTML is searched before the teaser.
        foreach ([$item->get_content(), $item->get_description()] as $html) {
            $imageUrl = $html ? $this->firstImageInHtml($html) : null;
            if ($imageUrl) {
                return $imageUrl;
            }
        }

        return $this->extractOgImage($item->get_link());
    }

    /**
     * Many feeds (e.g. Golem) end every item's HTML with a 1x1 tracking pixel,
     * which must never become the item image: it is invisible, and every client
     * would load the tracker.
     */
    private function firstImageInHtml(string $html): ?string
    {
        preg_match_all('/<img\b[^>]*>/i', $html, $tags);

        foreach ($tags[0] as $tag) {
            if (! preg_match('/\ssrc=["\']([^"\']+)["\']/i', $tag, $src)) {
                continue;
            }

            $url = html_entity_decode($src[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($this->isUsableImage($url, $this->htmlAttribute($tag, 'width'), $this->htmlAttribute($tag, 'height'))) {
                return $url;
            }
        }

        return null;
    }

    private function htmlAttribute(string $tag, string $name): ?string
    {
        return preg_match('/\s'.$name.'=["\']?([^"\'\s>]+)/i', $tag, $matches) ? $matches[1] : null;
    }

    private function isUsableImage(string $url, int|string|null $width, int|string|null $height): bool
    {
        foreach ([$width, $height] as $size) {
            if ($size !== null && $size !== '' && (int) $size <= 1) {
                return false;
            }
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach (self::TRACKER_HOSTS as $trackerHost) {
            if ($host === $trackerHost || str_ends_with($host, '.'.$trackerHost)) {
                return false;
            }
        }

        return true;
    }

    private function extractOgImage(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        try {
            $response = Http::timeout(5)->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; FeedBot/1.0)',
            ])->get($url);

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();

            if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
                return $matches[1];
            }

            if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\'][^>]*>/i', $html, $matches)) {
                return $matches[1];
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
