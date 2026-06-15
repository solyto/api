<?php

namespace App\Api\Libraries\Services\External;

use App\Api\Libraries\DTOs\BookReleaseDTO;
use App\Api\Libraries\DTOs\BookSearchResultDTO;
use App\Api\Libraries\Enums\BookServiceEnum;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class HardcoverService
{
    private const string API_URL = 'https://api.hardcover.app/v1/graphql';
    private const string BOOK_URL = 'https://hardcover.app/books/%s';

    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.hardcover.api_key') ?? '';
    }

    public static function getReleaseUrl(string $slug): string
    {
        return sprintf(self::BOOK_URL, $slug);
    }

    public function importFromUrl(string $url): ?BookReleaseDTO
    {
        $slug = $this->getSlugFromUrl($url);
        $book = $this->getBook($slug);

        if (!$book) {
            return null;
        }

        return new BookReleaseDTO(
            title: $book['title'],
            author: $book['contributions'][0]['author']['name'] ?? null,
            url: self::getReleaseUrl($book['slug']),
            provider: BookServiceEnum::HARDCOVER->value,
            id: $book['id'],
            description: $book['description'],
            authorId: $book['contributions'][0]['author']['id'] ?? null,
            pageCount: $book['pages'],
            cover: $book['default_cover_edition']['image']['url'] ?? $book['image']['url'] ?? null,
            releaseDate: $book['release_date'] ? Carbon::createFromFormat('Y-m-d', $book['release_date']) : null
        );
    }

    public function searchBooks(string $query): ?array
    {
        $response = $this->post('
            query SearchBooks($query: String!) {
                search(
                    query: $query,
                    query_type: "Book",
                    per_page: 10,
                    page: 1
                ) {
                    results
                }
            }
        ', ['query' => $query]);

        $hits = $response['data']['search']['results']['hits'] ?? null;

        if (!is_array($hits)) {
            return null;
        }

        return array_map(function ($hit) {
            $doc = $hit['document'];
            return new BookSearchResultDTO(
                id: (int) $doc['id'],
                title: $doc['title'],
                author: $doc['author_names'][0] ?? null,
                cover: !empty($doc['image']['url']) ? $doc['image']['url'] : null,
                releaseYear: isset($doc['release_year']) ? (int) $doc['release_year'] : null,
                provider: BookServiceEnum::HARDCOVER->value,
                url: self::getReleaseUrl($doc['slug']),
            );
        }, $hits);
    }

    public function getNewReleases(string $author): ?array
    {
        $response = $this->post('
            query SearchBooks($authorName: String!) {
                books(
                    where: {
                        contributions: {
                            author: { name: { _eq: $authorName } }
                        }
                        release_date: { _is_null: false }
                    }
                    order_by: { release_date: desc }
                    limit: 5
                ) {
                    id
                    slug
                    title
                    release_date
                    description
                    pages
                    image {
                        url
                    }
                    contributions {
                        author {
                            id
                            name
                        }
                    }
                }
            }
        ', ['authorName' => $author]);

        $books = $response['data']['books'] ?? null;

        if (!$books) {
            return null;
        }

        $timeframe = now()->subYear();
        $maxAllowedDate = now()->addYear();
        $newBooks = [];

        foreach ($books as $book) {
            if ($book['release_date'] === null) {
                continue;
            }

            $releaseDate = Carbon::createFromFormat('Y-m-d', $book['release_date']);

            if ($releaseDate->isAfter($timeframe) && !$releaseDate->isAfter($maxAllowedDate)) {
                $newBooks[] = $book;
            }
        }

        return $newBooks;
    }

    private function getBook(string $slug): ?array
    {
        $response = $this->post('
            query GetBook($slug: String!) {
                books(
                    where: { slug: { _eq: $slug } }
                    limit: 1
                ) {
                    id
                    slug
                    title
                    release_date
                    description
                    pages
                    image {
                        url
                    }
                    default_cover_edition {
                        image {
                            url
                        }
                    }
                    contributions {
                        author {
                            id
                            name
                        }
                    }
                }
            }
        ', ['slug' => $slug]);

        $books = $response['data']['books'] ?? [];

        return !empty($books) ? $books[0] : null;
    }

    private function post(string $query, array $variables): ?array
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
        ])->post(self::API_URL, ['query' => $query, 'variables' => $variables]);

        if (!$response->successful()) {
            return null;
        }

        return $response->json();
    }

    private function getSlugFromUrl(string $url): string
    {
        $parts = explode('/', $url);
        return end($parts);
    }
}
