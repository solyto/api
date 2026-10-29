<?php

namespace App\Shared\Services;

use App\Api\Clipboard\Services\ClipboardService;
use App\Api\Dashboard\DTOs\DetectionResult;
use App\Api\Dashboard\Enums\QuickAddContentType;
use App\Api\Feeds\Services\FeedService;
use App\Api\Libraries\Enums\BookServiceEnum;
use App\Api\Libraries\Enums\GameServiceEnum;
use App\Api\Libraries\Enums\MovieServiceEnum;
use App\Api\Libraries\Enums\MusicServiceEnum;
use App\Api\Libraries\Enums\RecipeServiceEnum;
use App\Api\Libraries\Models\LibraryBook;
use App\Api\Libraries\Models\LibraryGame;
use App\Api\Libraries\Models\LibraryMovie;
use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Libraries\Models\LibraryRecipe;
use App\Api\Libraries\Services\LibraryBookService;
use App\Api\Libraries\Services\LibraryGameService;
use App\Api\Libraries\Services\LibraryLinkService;
use App\Api\Libraries\Services\LibraryMovieService;
use App\Api\Libraries\Services\LibraryMusicService;
use App\Api\Libraries\Services\LibraryPlantService;
use App\Api\Libraries\Services\LibraryQuoteService;
use App\Api\Libraries\Services\LibraryRecipeService;
use App\Api\Notes\Services\NoteService;
use App\Api\Todos\Models\Todo;
use App\Api\Todos\Services\TodoService;
use App\Api\Users\Models\User;
use App\Shared\Helpers\UrlHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class QuickAddService
{
    private const float CONFIRMATION_THRESHOLD = 0.60;

    public function detect(string $content): DetectionResult
    {
        if (Str::contains($content, ['due', 'repeat', 'link:'])) {
            return $this->makeResult($content, QuickAddContentType::Todo, 0.70);
        }

        if (UrlHelper::hasUrl($content)) {
            return $this->detectBasedOnUrl($content);
        }

        if (Str::contains($content, ['/', '#'])) {
            return $this->makeResult($content, QuickAddContentType::Todo, 0.50);
        }

        return $this->makeResult($content, QuickAddContentType::Note, 0.50);
    }

    private function detectBasedOnUrl(string $content): DetectionResult
    {
        $content = UrlHelper::extractUrl($content) ?? $content;

        if (Str::contains($content, [MusicServiceEnum::DEEZER->baseUrl(), MusicServiceEnum::DISCOGS->baseUrl(), MusicServiceEnum::SPOTIFY->baseUrl()])) {
            return $this->makeResult($content, QuickAddContentType::Music, 0.95);
        }

        if (Str::contains($content, [BookServiceEnum::HARDCOVER->baseUrl(), BookServiceEnum::GOODREADS->baseUrl()])) {
            return $this->makeResult($content, QuickAddContentType::Books, 0.95);
        }

        if (Str::contains($content, MovieServiceEnum::IMDB->baseUrl())) {
            return $this->makeResult($content, QuickAddContentType::Movies, 0.95);
        }

        if (Str::contains($content, [GameServiceEnum::STEAM->baseUrl(), GameServiceEnum::BGG->baseUrl()])) {
            return $this->makeResult($content, QuickAddContentType::Games, 0.95);
        }

        if (Str::contains($content, RecipeServiceEnum::CHEFKOCH->baseUrl())) {
            return $this->makeResult($content, QuickAddContentType::Recipes, 0.95);
        }

        return $this->makeResult($content, QuickAddContentType::Links, 0.95);
    }

    private function makeResult(string $content, QuickAddContentType $type, float $confidence): DetectionResult
    {
        return new DetectionResult($content, $type, $confidence, $confidence < self::CONFIRMATION_THRESHOLD);
    }

    public function commit(User $user, string $content, QuickAddContentType $contentType, ?array $metadata): ?Model
    {
        $metadata ??= [];

        if (UrlHelper::hasUrl($content)) {
            $content = UrlHelper::extractUrl($content) ?? $content;
        }

        return match ($contentType) {
            QuickAddContentType::Links => app(LibraryLinkService::class)->create($user, [
                'url' => $content,
                'title' => $metadata['title'] ?? null,
            ]),

            QuickAddContentType::Todo => $this->commitTodo($user, $content, $metadata),

            QuickAddContentType::Note => app(NoteService::class)->create($user, [
                'title' => $metadata['title'] ?? $content,
            ]),

            QuickAddContentType::Quotes => app(LibraryQuoteService::class)->create($user, [
                'quote' => $metadata['quote'] ?? $content,
                'author' => $metadata['author'] ?? null,
                'source' => $metadata['source'] ?? null,
            ]),

            QuickAddContentType::Recipes => $this->commitRecipe($user, $content, $metadata),

            QuickAddContentType::Plants => app(LibraryPlantService::class)->create($user, [
                'name' => $metadata['name'] ?? $content,
            ]),

            QuickAddContentType::Feed => app(FeedService::class)->createSubscription(
                $user,
                $metadata['title'] ?? $content,
                $content,
                null,
                null,
            ),

            QuickAddContentType::Clipboard => app(ClipboardService::class)->store($user, [
                'content' => $metadata['content'] ?? $content,
                'type' => 'text',
            ]),

            QuickAddContentType::Music => $this->commitMusic($user, $content),
            QuickAddContentType::Books => $this->commitBook($user, $content),
            QuickAddContentType::Movies => $this->commitMovie($user, $content),
            QuickAddContentType::Games => $this->commitGame($user, $content),
        };
    }

    private function commitTodo(User $user, string $content, ?array $metadata): Todo
    {
        $todoService = app(TodoService::class);

        return $todoService->create($user, $todoService->parse($user, ['title' => $metadata['title'] ?? $content]));
    }

    private function commitMusic(User $user, string $url): ?LibraryMusic
    {
        $service = app(LibraryMusicService::class);

        $dto = match (true) {
            Str::contains($url, MusicServiceEnum::DISCOGS->baseUrl()) => $service->import(MusicServiceEnum::DISCOGS, $url),
            Str::contains($url, MusicServiceEnum::SPOTIFY->baseUrl()) => $service->import(MusicServiceEnum::SPOTIFY, $url),
            default => $service->import(MusicServiceEnum::DEEZER, $url),
        };

        if ($dto === null) {
            return null;
        }

        return $service->create($user, [
            'title' => $dto->getTitle(),
            'artist' => $dto->getArtist(),
            'format' => $dto->getRecordType(),
            'cover_path' => $dto->getCover(),
            'link' => $dto->getUrl(),
            'publication_year' => $dto->getReleaseDate()?->year,
        ]);
    }

    private function commitBook(User $user, string $url): ?LibraryBook
    {
        $service = app(LibraryBookService::class);

        $dto = Str::contains($url, BookServiceEnum::GOODREADS->baseUrl())
            ? $service->import(BookServiceEnum::GOODREADS, $url)
            : $service->import(BookServiceEnum::HARDCOVER, $url);

        if ($dto === null) {
            return null;
        }

        return $service->create($user, [
            'title' => $dto->getTitle(),
            'author' => $dto->getAuthor(),
            'pages' => $dto->getPageCount(),
            'cover_path' => $dto->getCover(),
            'link' => $dto->getUrl(),
            'publication_year' => $dto->getReleaseDate()?->year,
            'summary' => $dto->getDescription(),
        ]);
    }

    private function commitMovie(User $user, string $url): ?LibraryMovie
    {
        $service = app(LibraryMovieService::class);

        $dto = $service->import(MovieServiceEnum::IMDB, $url);

        if ($dto === null) {
            return null;
        }

        return $service->create($user, [
            'title' => $dto->getTitle(),
            'category' => $dto->getType() === 'series' ? 'series' : 'movie',
            'publication_year' => $dto->getReleaseYear(),
            'cover_path' => $dto->getCover(),
            'link' => $dto->getLink(),
        ]);
    }

    private function commitGame(User $user, string $url): ?LibraryGame
    {
        $service = app(LibraryGameService::class);

        $isBgg = Str::contains($url, GameServiceEnum::BGG->baseUrl());
        $dto = $isBgg
            ? $service->import(GameServiceEnum::BGG, $url)
            : $service->import(GameServiceEnum::STEAM, $url);

        if ($dto === null) {
            return null;
        }

        return $service->create($user, [
            'title' => $dto->getTitle(),
            'platform' => $isBgg ? 'boardgame' : 'pc',
            'cover_path' => $dto->getCover(),
            'link' => $dto->getUrl(),
            'developer' => $dto->getDeveloper(),
            'publisher' => $dto->getPublisher(),
            'publication_year' => $dto->getPublicationYear(),
        ]);
    }

    private function commitRecipe(User $user, string $content, ?array $metadata): ?LibraryRecipe
    {
        $service = app(LibraryRecipeService::class);

        if (Str::contains($content, RecipeServiceEnum::CHEFKOCH->baseUrl())) {
            $dto = $service->import(RecipeServiceEnum::CHEFKOCH, $content);

            if ($dto === null) {
                return null;
            }

            return $service->create($user, [
                'title' => $dto->getTitle(),
                'link' => $dto->getUrl(),
                'cover_path' => $dto->getCover(),
                'description' => $dto->getDescription(),
                'ingredients' => $dto->getIngredients(),
                'steps' => $dto->getSteps(),
                'time_to_make' => $dto->getTimeToMake(),
                'servings' => $dto->getServings(),
                'rating' => $dto->getRating() ? (int) round($dto->getRating()) : null,
            ]);
        }

        return $service->create($user, [
            'title' => $metadata['title'] ?? $content,
            'link' => $content,
        ]);
    }
}
