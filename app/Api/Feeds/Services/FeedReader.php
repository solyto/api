<?php

namespace App\Api\Feeds\Services;

use SimplePie\SimplePie;

class FeedReader {
    private SimplePie $pie;

    public function __construct()
    {
        $this->pie = new SimplePie();
        $this->pie->enable_cache(false);
    }

    public function getItems(string $url): array | bool
    {
        $this->pie->set_feed_url($url);
        $this->pie->init();

        $items = $this->pie->get_items();

        if (empty($items)) {
            return false;
        }

        return $items;
    }

    public function getFeedData(string $url): array | false
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

    public function extractImageUrl(\SimplePie\Item $item): ?string
    {
        $thumbnail = $item->get_item_tags('http://search.yahoo.com/mrss/', 'thumbnail');
        if (!empty($thumbnail[0]['attribs']['']['url'])) {
            return $thumbnail[0]['attribs']['']['url'];
        }

        $content = $item->get_item_tags('http://search.yahoo.com/mrss/', 'content');
        if (!empty($content)) {
            foreach ($content as $mediaContent) {
                $medium = $mediaContent['attribs']['']['medium'] ?? '';
                $type = $mediaContent['attribs']['']['type'] ?? '';
                if ($medium === 'image' || str_starts_with($type, 'image/')) {
                    return $mediaContent['attribs']['']['url'] ?? null;
                }
            }
        }

        $enclosures = $item->get_enclosures();
        if ($enclosures) {
            foreach ($enclosures as $enclosure) {
                if ($enclosure->get_medium() === 'image' || str_starts_with((string) $enclosure->get_type(), 'image/')) {
                    return $enclosure->get_link();
                }
            }
        }

        foreach ([$item->get_content(), $item->get_description()] as $html) {
            if ($html && preg_match('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }
}
