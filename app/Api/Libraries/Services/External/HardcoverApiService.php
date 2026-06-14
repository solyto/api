<?php

namespace App\Api\Libraries\Services\External;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class HardcoverApiService
{
    private const API_URL = 'https://api.hardcover.app/v1/graphql';

    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.hardcover.api_key') ?? '';
    }

    public function getNewReleases(string $author): ?array
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
        ])
        ->post(self::API_URL, [
            'query' => '
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
            ',
            'variables' => [
                'authorName' => $author,
            ]
        ]);

        if (!$response->successful()) {
            return null;
        }

        $books =  $response->json()['data']['books'];
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

    public function searchBooks(string $title): ?array
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
        ])
        ->post(self::API_URL, [
            'query' => '
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
            ',
            'variables' => [
                'title' => '%' . $title . '%',
            ],
        ]);

        if (!$response->successful()) {
            return null;
        }

        return $response->json()['data']['books'] ?? null;
    }

    public function getBook(string $slug): ?array
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
        ])
                        ->post(self::API_URL, [
                            'query' => '
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
                            ',
                            'variables' => [
                                'slug' => $slug,
                            ]
                        ]);

        if (!$response->successful()) {
            return null;
        }

        $books = $response->json()['data']['books'];

        return !empty($books) ? $books[0] : null;
    }
}
