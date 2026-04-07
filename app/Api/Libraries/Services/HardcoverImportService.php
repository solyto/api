<?php

namespace App\Api\Libraries\Services;

use App\Api\Libraries\DTOs\HardcoverBookDTO;
use App\Api\Libraries\Services\External\HardcoverApiService;
use Carbon\Carbon;

class HardcoverImportService
{
    public function __construct(
        private readonly HardcoverApiService $hardcoverApiService
    ) {}

    public function importBookFromUrl(string $url): ?HardcoverBookDTO
    {
        $slug = $this->getSlugFromUrl($url);
        $book = $this->hardcoverApiService->getBook($slug);

        if (!$book) {
            return null;
        }

        return new HardcoverBookDTO(
            id: $book['id'],
            title: $book['title'],
            description: $book['description'],
            author: $book['contributions'][0]['author']['name'] ?? null,
            authorId: $book['contributions'][0]['author']['id'] ?? null,
            pageCount: $book['pages'],
            cover: $book['image']['url'] ?? null,
            url: 'https://hardcover.app/books/' . $book['slug'],
            releaseDate: Carbon::createFromFormat('Y-m-d', $book['release_date'])
        );
    }

    private function getSlugFromUrl(string $url): string
    {
        $parts = explode('/', $url);
        return end($parts);
    }
}
