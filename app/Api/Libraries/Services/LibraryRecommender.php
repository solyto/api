<?php

namespace App\Api\Libraries\Services;

use App\Api\Libraries\Enums\LibraryTypeEnum;
use App\Api\Users\Models\User;
use App\Shared\Enums\AiUsageFeatureEnum;

class LibraryRecommender
{
    private const RESPONSE_SCHEMA = [
        'type' => 'json_schema',
        'json_schema' => [
            'name' => 'recommendations_response',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'recommendations' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'creator' => ['type' => 'string'],
                                'genre' => ['type' => 'string'],
                            ],
                            'required' => ['title', 'creator', 'genre'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['recommendations'],
                'additionalProperties' => false,
            ],
        ],
    ];
    private const BOOKS_PROMPT = 'You are a book recommendation service. Consider the favorites of the user and look at similar books the user could like or new releases of the presented authors.';
    private const MUSIC_PROMPT = 'You are a music recommendation service. Consider the favorites of the user and look at similar music the user could like or new releases in the presented genres/from the presented artists.';
    private const BOOKS_MESSAGE = 'Recommend 10 new books for me based on my favorite books: %s';
    private const MUSIC_MESSAGE = 'Recommend me some new music I could listen to. I like the genres %s and the following artists: %s.';

    public function __construct(
        private readonly LibraryTypeEnum $type,
        private readonly User $user
    )
    {
        if (!$type->hasRecommender()) {
            throw new \Exception('No recommender for type ' . $this->type->value);
        }
    }

    public function favorite(): array
    {
        $item = $this->type->getModel()::forUser($this->user->id)->where('rating', '>=', 4)->inRandomOrder()->first();

        return [
            'id' => $item->id,
            'title' => $item->title,
            'creator' => $item->author ?? $item->artist,
            'cover' => $item->cover_path,
            'link' => $item->link,
        ];
    }

    public function unrated(): array
    {
        $item = $this->type->getModel()::forUser($this->user->id)->where('rating', null)->inRandomOrder()->first();

        return [
            'id' => $item->id,
            'title' => $item->title,
            'creator' => $item->author ?? $item->artist,
            'cover' => $item->cover_path,
            'link' => $item->link,
        ];
    }

    public function random(): ?array
    {
        $item =  $this->type->getModel()::forUser($this->user->id)->inRandomOrder()->first();

        if (!$item) {
            return null;
        }

        return [
            'id' => $item->id,
            'title' => $item->title,
            'creator' => $item->author ?? $item->artist,
            'cover' => $item->cover_path,
            'link' => $item->link,
        ];
    }

    public function new(): ?array
    {
        $favorites = $this->type->getModel()::forUser($this->user->id)->where('rating', '>=', 4)->inRandomOrder()->limit(10)->get();
        $prompt = $this->type === LibraryTypeEnum::BOOK ? self::BOOKS_PROMPT : self::MUSIC_PROMPT;
        $message = $this->type === LibraryTypeEnum::BOOK ? $this->getBooksMessage($favorites) : $this->getMusicMessage($favorites);
        $aiService = app(AiService::class);
        $response = $aiService->respondStructured($prompt, $message, self::RESPONSE_SCHEMA);
        $aiService->saveUsageForUser($this->user, AiUsageFeatureEnum::LIBRARY_RECOMMENDER);

        return $response;
    }

    private function getBooksMessage($favorites): string {
        $genres = [];
        $artists = [];

        foreach ($favorites as $favorite) {
            if (!in_array($favorite->genre, $genres)) {
                $genres[] = $favorite->genre;
            }
            if (!in_array($favorite->artist, $artists)) {
                $artists[] = $favorite->artist;
            }
        }

        return sprintf(self::MUSIC_MESSAGE, implode(', ', $genres), implode(', ', $artists));
    }

    private function getMusicMessage($favorites): string {
        $items = $favorites->map(fn($book) => $book->title . ' by ' . $book->author)->toArray();
        return sprintf(self::BOOKS_MESSAGE, implode(', ', $items));
    }
}
