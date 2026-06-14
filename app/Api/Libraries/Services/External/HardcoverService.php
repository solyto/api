<?php

namespace App\Api\Libraries\Services\External;

use App\Api\Libraries\DTOs\HardcoverBookDTO;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class HardcoverService
{
    private const string API_URL = 'https://api.hardcover.app/v1/graphql';

    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.hardcover.api_key') ?? '';
    }

    public function importFromUrl(string $url): ?HardcoverBookDTO
    {
        $slug = $this->getSlugFromUrl($url);
        $book = $this->getBook($slug);

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
            releaseDate: $book['release_date'] ? Carbon::createFromFormat('Y-m-d', $book['release_date']) : null
        );
    }

    public function searchBooks(string $title): ?array
    {
        $response = $this->post('
            query SearchBooks($title: String!) {
                books(
                    where: { title: { _ilike: $title } }
                    order_by: { users_count: desc }
                    limit: 10
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
        ', ['title' => '%' . $title . '%']);

        return $response['data']['books'] ?? null;
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
