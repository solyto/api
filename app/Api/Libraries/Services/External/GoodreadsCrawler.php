<?php

namespace App\Api\Libraries\Services\External;

use App\Api\Libraries\DTOs\GoodreadsBookDTO;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;

class GoodreadsCrawler
{
    public function getBookFromUrl(string $url): ?GoodreadsBookDTO
    {
        try {
            $html = file_get_contents($url);
            if (!$html) {
                return null;
            }

            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);

            // Extract book title
            $title = $this->extractTitle($xpath);

            // Extract author(s) - excluding translators
            $authors = $this->extractAuthors($xpath);

            // Extract page count
            $pageCount = $this->extractPageCount($xpath);

            // Extract publication date
            $publicationDate = $this->extractPublicationDate($xpath);

            // Extract cover image
            $coverImage = $this->extractCoverImage($xpath);

            return new GoodreadsBookDTO(
                title: $title,
                author: $authors[0] ?? null,
                pageCount: $pageCount,
                cover: $coverImage,
                url: $url,
                releaseDate: $publicationDate ?? null
            );
        } catch (\Exception $e) {
            return null;
        }
    }

    private function extractTitle(DOMXPath $xpath): ?string
    {
        // <h1 class="Text Text__title1" data-testid="bookTitle">
        $titleNodes = $xpath->query('//h1[@data-testid="bookTitle"]');
        if ($titleNodes->length > 0) {
            return trim($titleNodes->item(0)->textContent);
        }
        return null;
    }

    private function extractAuthors(DOMXPath $xpath): array
    {
        $authors = [];

        // <span class="ContributorLink__name" data-testid="name">
        $authorNodes = $xpath->query('//span[@data-testid="name"]');

        foreach ($authorNodes as $authorNode) {
            $authorName = trim($authorNode->textContent);

            // Check if this author has a translator role
            $parentSpan = $authorNode->parentNode;
            $roleNode = $xpath->query('.//span[@data-testid="role"]', $parentSpan);

            // Skip if this is a translator
            if ($roleNode->length > 0) {
                $role = trim($roleNode->item(0)->textContent);
                if (stripos($role, 'translator') !== false) {
                    continue;
                }
            }

            if (!empty($authorName)) {
                $authors[] = $authorName;
            }
        }

        return array_unique($authors);
    }

    private function extractPageCount(DOMXPath $xpath): ?int
    {
        // <p data-testid="pagesFormat">513 pages, Kindle Edition</p>
        $pagesNodes = $xpath->query('//p[@data-testid="pagesFormat"]');
        if ($pagesNodes->length > 0) {
            $pagesText = trim($pagesNodes->item(0)->textContent);
            // Extract number from "513 pages, Kindle Edition"
            if (preg_match('/(\d+)\s+pages?/i', $pagesText, $matches)) {
                return (int)$matches[1];
            }
        }
        return null;
    }

    private function extractPublicationDate(DOMXPath $xpath): ?Carbon
    {
        // <p data-testid="publicationInfo">First published November 12, 2024</p>
        $pubNodes = $xpath->query('//p[@data-testid="publicationInfo"]');
        if ($pubNodes->length > 0) {
            $pubText = trim($pubNodes->item(0)->textContent);

            // Extract date from "First published November 12, 2024"
            if (preg_match('/(?:first published|published)\s+(.+)/i', $pubText, $matches)) {
                $dateString = trim($matches[1]);

                try {
                    // Try to parse the date
                    return Carbon::parse($dateString);
                } catch (\Exception $e) {
                    // If parsing fails, try to extract just the year
                    if (preg_match('/(\d{4})/', $dateString, $yearMatches)) {
                        return $yearMatches[1] . '-01-01';
                    }
                }
            }
        }
        return null;
    }

    private function extractCoverImage(DOMXPath $xpath): ?string
    {
        // <div class="BookCover__image"><div><img class="ResponsiveImage" src="...">
        $imageNodes = $xpath->query('//div[@class="BookCover__image"]//img[@class="ResponsiveImage"]');
        if ($imageNodes->length > 0) {
            $src = $imageNodes->item(0)->getAttribute('src');

            // Skip the default "no-cover" image
            if ($src && stripos($src, 'no-cover.png') === false) {
                return $src;
            }
        }
        return null;
    }
}
