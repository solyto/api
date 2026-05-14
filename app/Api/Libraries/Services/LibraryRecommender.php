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
    private const BOOKS_PROMPT = 'You are a book recommendation service. Based on the user\'s favorites (including their genres), recommend books they are likely to enjoy.';
    private const MUSIC_PROMPT = 'You are a music recommendation service. Based on the user\'s favorites (including genres, type, and format), recommend music they are likely to enjoy.';

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
        $model = $this->type->getModel();
        $isBook = $this->type === LibraryTypeEnum::BOOK;

        $favorites = $model::forUser($this->user->id)
            ->where('rating', '>=', 4)
            ->with('genres')
            ->orderByDesc('rating')
            ->when($isBook, fn($q) => $q->orderByDesc('finished_at'))
            ->limit(10)
            ->get();

        if ($favorites->isEmpty()) {
            return null;
        }

        $prompt = $isBook ? self::BOOKS_PROMPT : self::MUSIC_PROMPT;
        $message = $isBook
            ? $this->getBooksMessage($favorites)
            : $this->getMusicMessage($favorites);

        $aiService = app(AiService::class);
        $response = $aiService->respondStructured($prompt, $message, self::RESPONSE_SCHEMA);
        $aiService->saveUsageForUser($this->user, AiUsageFeatureEnum::LIBRARY_RECOMMENDER);

        return $response;
    }

    private function getBooksMessage($favorites): string
    {
        $items = $favorites->map(function ($book) {
            $genres = $book->genres->pluck('title')->join(', ');
            return '- ' . $book->title . ' by ' . $book->author . ($genres ? ' [' . $genres . ']' : '');
        })->join("\n");

        return "Recommend 10 books for me.\n\nMy top favorites:\n{$items}";
    }

    private function getMusicMessage($favorites): string
    {
        $items = $favorites->map(function ($album) {
            $genres = $album->genres->pluck('title')->join(', ');
            $meta = collect([$album->type, $album->format])->filter()->join(', ');
            return '- ' . $album->artist . ' – ' . $album->title
                . ($meta ? ' (' . $meta . ')' : '')
                . ($genres ? ' [' . $genres . ']' : '');
        })->join("\n");

        return "Recommend 10 albums or releases for me.\n\nMy top favorites:\n{$items}";
    }
}
