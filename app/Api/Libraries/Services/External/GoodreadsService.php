<?php

namespace App\Api\Libraries\Services\External;

use App\Api\Libraries\DTOs\BookReleaseDTO;
use App\Api\Libraries\Enums\BookServiceEnum;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;

class GoodreadsService
{
    public function importFromUrl(string $url): ?BookReleaseDTO
    {
        try {
            $html = file_get_contents($url);
            if (!$html) {
                return null;
            }

            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);

            $title = $this->extractTitle($xpath);
            $authors = $this->extractAuthors($xpath);
            $pageCount = $this->extractPageCount($xpath);
            $publicationDate = $this->extractPublicationDate($xpath);
            $coverImage = $this->extractCoverImage($xpath);

            return new BookReleaseDTO(
                title: $title,
                author: $authors[0] ?? null,
                url: $url,
                provider: BookServiceEnum::GOODREADS->value,
                pageCount: $pageCount,
                cover: $coverImage,
                releaseDate: $publicationDate ?? null
            );
        } catch (\Exception $e) {
            return null;
        }
    }

    private function extractTitle(DOMXPath $xpath): ?string
    {
        $titleNodes = $xpath->query('//h1[@data-testid="bookTitle"]');
        if ($titleNodes->length > 0) {
            return trim($titleNodes->item(0)->textContent);
        }
        return null;
    }

    private function extractAuthors(DOMXPath $xpath): array
    {
        $authors = [];

        $authorNodes = $xpath->query('//span[@data-testid="name"]');

        foreach ($authorNodes as $authorNode) {
            $authorName = trim($authorNode->textContent);

            $parentSpan = $authorNode->parentNode;
            $roleNode = $xpath->query('.//span[@data-testid="role"]', $parentSpan);

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
        $pagesNodes = $xpath->query('//p[@data-testid="pagesFormat"]');
        if ($pagesNodes->length > 0) {
            $pagesText = trim($pagesNodes->item(0)->textContent);
            if (preg_match('/(\d+)\s+pages?/i', $pagesText, $matches)) {
                return (int) $matches[1];
            }
        }
        return null;
    }

    private function extractPublicationDate(DOMXPath $xpath): ?Carbon
    {
        $pubNodes = $xpath->query('//p[@data-testid="publicationInfo"]');
        if ($pubNodes->length > 0) {
            $pubText = trim($pubNodes->item(0)->textContent);

            if (preg_match('/(?:first published|published)\s+(.+)/i', $pubText, $matches)) {
                $dateString = trim($matches[1]);

                try {
                    return Carbon::parse($dateString);
                } catch (\Exception $e) {
                    if (preg_match('/(\d{4})/', $dateString, $yearMatches)) {
                        return Carbon::createFromFormat('Y-m-d', $yearMatches[1] . '-01-01');
                    }
                }
            }
        }
        return null;
    }

    private function extractCoverImage(DOMXPath $xpath): ?string
    {
        $imageNodes = $xpath->query('//div[@class="BookCover__image"]//img[@class="ResponsiveImage"]');
        if ($imageNodes->length > 0) {
            $src = $imageNodes->item(0)->getAttribute('src');

            if ($src && stripos($src, 'no-cover.png') === false) {
                return $src;
            }
        }
        return null;
    }
}
