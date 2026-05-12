<?php

namespace App\Api\Dashboard\Services;

use App\Api\Dashboard\DTOs\DetectionResult;
use App\Api\Dashboard\Enums\QuickAddContentType;
use App\Api\Feeds\Services\FeedService;
use App\Api\Libraries\Services\LibraryBookService;
use App\Api\Libraries\Services\LibraryGameService;
use App\Api\Libraries\Services\LibraryLinkService;
use App\Api\Libraries\Services\LibraryMovieService;
use App\Api\Libraries\Services\LibraryMusicService;
use App\Api\Libraries\Services\LibraryPlantService;
use App\Api\Libraries\Services\LibraryQuoteService;
use App\Api\Libraries\Services\LibraryRecipeService;
use App\Api\Notes\Services\NoteService;
use App\Api\Todos\Services\TodoService;
use App\Api\Users\Models\User;
use Illuminate\Support\Str;

class QuickAddService
{
    public function __construct(
        private readonly LibraryLinkService $linkService,
        private readonly LibraryMusicService $musicService,
        private readonly LibraryBookService $bookService,
        private readonly LibraryMovieService $movieService,
        private readonly LibraryGameService $gameService,
        private readonly LibraryRecipeService $recipeService,
        private readonly LibraryPlantService $plantService,
        private readonly LibraryQuoteService $quoteService,
        private readonly TodoService $todoService,
        private readonly NoteService $noteService,
        private readonly FeedService $feedService,
    ) {}

    public function detect(string $content): DetectionResult
    {
        if (Str::contains($content, 'https://') || Str::contains($content, 'http://')) {
            return $this->detectBasedOnUrl($content);
        }

        if (Str::contains($content, ['due', 'repeat'])) {
            return $this->todo($content, 0.70);
        }

        if (Str::contains($content, ['/', '#'])) {
            return $this->todo($content, 0.50);
        }

        return $this->note($content, 0.50);
    }

    private function detectBasedOnUrl(string $url): ?DetectionResult
    {
        if (Str::contains($url, ['deezer.com', 'discogs.com'])) {
            return $this->music($url, 0.95);
        }

        if (Str::contains($url, ['hardcover.app', 'goodreads.com'])) {
            return $this->books($url, 0.95);
        }

        if (Str::contains($url, 'imdb.com')) {
            return $this->movies($url, 0.95);
        }

        if (Str::contains($url, ['store.steampowered.com', 'boardgamegeek.com'])) {
            return $this->games($url, 0.95);
        }

        return $this->links($url, 0.95);
    }

    private function music(string $url, float $confidenceScore, ?array $metadata = null): DetectionResult
    {
        return new DetectionResult($url, QuickAddContentType::Music, $confidenceScore, $metadata);
    }

    private function books(string $url, float $confidenceScore, ?array $metadata = null): DetectionResult
    {
        return new DetectionResult($url, QuickAddContentType::Books, $confidenceScore, $metadata);
    }

    private function movies(string $url, float $confidenceScore, ?array $metadata = null): DetectionResult
    {
        return new DetectionResult($url, QuickAddContentType::Movies, $confidenceScore, $metadata);
    }

    private function games(string $url, float $confidenceScore, ?array $metadata = null): DetectionResult
    {
        return new DetectionResult($url, QuickAddContentType::Games, $confidenceScore, $metadata);
    }

    private function links(string $url, float $confidenceScore, ?array $metadata = null): DetectionResult
    {
        return new DetectionResult($url, QuickAddContentType::Links, $confidenceScore, $metadata);
    }

    private function recipes(string $url, float $confidenceScore, ?array $metadata = null): DetectionResult
    {
        return new DetectionResult($url, QuickAddContentType::Recipes, $confidenceScore, $metadata);
    }

    private function plants(string $url, float $confidenceScore, ?array $metadata = null): DetectionResult
    {
        return new DetectionResult($url, QuickAddContentType::Plants, $confidenceScore, $metadata);
    }

    private function quotes(string $url, float $confidenceScore, ?array $metadata = null): DetectionResult
    {
        return new DetectionResult($url, QuickAddContentType::Quotes, $confidenceScore, $metadata);
    }

    private function todo(string $url, float $confidenceScore, ?array $metadata = null): DetectionResult
    {
        return new DetectionResult($url, QuickAddContentType::Todo, $confidenceScore, $metadata);
    }

    private function note(string $url, float $confidenceScore, ?array $metadata = null): DetectionResult
    {
        return new DetectionResult($url, QuickAddContentType::Note, $confidenceScore, $metadata);
    }

    private function feed(string $url, float $confidenceScore, ?array $metadata = null): DetectionResult
    {
        return new DetectionResult($url, QuickAddContentType::Feed, $confidenceScore, $metadata);
    }

    public function commit(User $user, string $url, QuickAddContentType $contentType, ?array $metadata): mixed
    {
        $metadata ??= [];

        return match ($contentType) {
            QuickAddContentType::Links   => $this->linkService->create($user, [
                'url' => $url,
                'title' => $metadata['title'] ?? null,
            ]),

            QuickAddContentType::Todo    => $this->todoService->create($user, [
                'title' => $metadata['title'] ?? $url,
            ]),

            QuickAddContentType::Note    => $this->noteService->create($user, [
                'title' => $metadata['title'] ?? $url,
            ]),

            QuickAddContentType::Quotes  => $this->quoteService->create($user, [
                'quote'  => $metadata['quote'] ?? $url,
                'author' => $metadata['author'] ?? null,
                'source' => $metadata['source'] ?? null,
            ]),

            QuickAddContentType::Recipes => $this->recipeService->create($user, [
                'title' => $metadata['title'] ?? $url,
                'link'  => $url,
            ]),

            QuickAddContentType::Plants  => $this->plantService->create($user, [
                'name' => $metadata['name'] ?? $url,
            ]),

            QuickAddContentType::Feed    => $this->feedService->createSubscription(
                $user->id,
                $metadata['title'] ?? $url,
                $url,
                null,
                null,
            ),

            QuickAddContentType::Music   => $this->commitMusic($user, $url),
            QuickAddContentType::Books   => $this->commitBook($user, $url),
            QuickAddContentType::Movies  => $this->commitMovie($user, $url),
            QuickAddContentType::Games   => $this->commitGame($user, $url),
        };
    }

    private function commitMusic(User $user, string $url): mixed
    {
        $dto = Str::contains($url, 'discogs.com')
            ? $this->musicService->importFromDiscogs($url)
            : $this->musicService->importFromDeezer($url);

        if ($dto === null) {
            return null;
        }

        return $this->musicService->create($user, [
            'title' => $dto->getTitle(),
            'artist' => $dto->getArtist(),
            'type' => $dto->getRecordType(),
            'cover_path' => $dto->getCover(),
            'link' => $dto->getUrl(),
            'publication_year' => $dto->getReleaseDate()?->year,
        ]);
    }

    private function commitBook(User $user, string $url): mixed
    {
        $dto = Str::contains($url, 'goodreads.com')
            ? $this->bookService->importFromGoodreads($url)
            : $this->bookService->importFromHardcover($url);

        if ($dto === null) {
            return null;
        }

        $data = [
            'title' => $dto->getTitle(),
            'author' => $dto->getAuthor(),
            'pages' => $dto->getPageCount(),
            'cover_path' => $dto->getCover(),
            'link' => $dto->getUrl(),
            'publication_year' => $dto->getReleaseDate()?->year,
        ];

        if (method_exists($dto, 'getDescription')) {
            $data['summary'] = $dto->getDescription();
        }

        return $this->bookService->create($user, $data);
    }

    private function commitMovie(User $user, string $url): mixed
    {
        $dto = $this->movieService->importFromImdb($url);

        if ($dto === null) {
            return null;
        }

        return $this->movieService->create($user, [
            'title' => $dto->getTitle(),
            'category' => $dto->getType() === 'series' ? 'series' : 'movie',
            'publication_year' => $dto->getReleaseYear(),
            'cover_path' => $dto->getCover(),
            'link' => $dto->getLink(),
        ]);
    }

    private function commitGame(User $user, string $url): mixed
    {
        $isBgg = Str::contains($url, 'boardgamegeek.com');
        $dto = $isBgg
            ? $this->gameService->importFromBgg($url)
            : $this->gameService->importFromSteam($url);

        if ($dto === null) {
            return null;
        }

        return $this->gameService->create($user, [
            'title' => $dto->getTitle(),
            'platform' => $isBgg ? 'boardgame' : 'pc',
            'cover_path' => $dto->getCover(),
            'link' => $dto->getUrl(),
            'developer' => $isBgg ? $dto->getDesigner() : $dto->getDeveloper(),
            'publisher' => $dto->getPublisher(),
            'publication_year' => $isBgg
                ? $dto->getPublicationYear()
                : (is_string($dto->getReleaseDate()) ? (int) substr($dto->getReleaseDate(), 0, 4) : null),
        ]);
    }
}
