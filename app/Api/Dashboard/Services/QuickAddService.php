<?php

namespace App\Api\Dashboard\Services;

use App\Api\Dashboard\DTOs\DetectionResult;
use App\Api\Dashboard\Enums\QuickAddContentType;
use App\Api\Clipboard\Services\ClipboardService;
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
use App\Api\Tags\Models\Tag;
use App\Api\Todos\Models\TodoCategory;
use App\Api\Todos\Services\TodoService;
use App\Api\Users\Models\User;
use Illuminate\Support\Str;

class QuickAddService
{
    private const float CONFIRMATION_THRESHOLD = 0.60;

    public function detect(string $content): DetectionResult
    {
        if (Str::contains($content, 'https://') || Str::contains($content, 'http://')) {
            return $this->detectBasedOnUrl($content);
        }

        if (Str::contains($content, ['due', 'repeat'])) {
            return $this->makeResult($content, QuickAddContentType::Todo, 0.70);
        }

        if (Str::contains($content, ['/', '#'])) {
            return $this->makeResult($content, QuickAddContentType::Todo, 0.50);
        }

        return $this->makeResult($content, QuickAddContentType::Note, 0.50);
    }

    private function detectBasedOnUrl(string $content): DetectionResult
    {
        if (Str::contains($content, ['deezer.com', 'discogs.com'])) {
            return $this->makeResult($content, QuickAddContentType::Music, 0.95);
        }

        if (Str::contains($content, ['hardcover.app', 'goodreads.com'])) {
            return $this->makeResult($content, QuickAddContentType::Books, 0.95);
        }

        if (Str::contains($content, 'imdb.com')) {
            return $this->makeResult($content, QuickAddContentType::Movies, 0.95);
        }

        if (Str::contains($content, ['store.steampowered.com', 'boardgamegeek.com'])) {
            return $this->makeResult($content, QuickAddContentType::Games, 0.95);
        }

        return $this->makeResult($content, QuickAddContentType::Links, 0.95);
    }

    private function makeResult(string $content, QuickAddContentType $type, float $confidence): DetectionResult
    {
        return new DetectionResult($content, $type, $confidence, $confidence < self::CONFIRMATION_THRESHOLD);
    }

    public function commit(User $user, string $url, QuickAddContentType $contentType, ?array $metadata): mixed
    {
        $metadata ??= [];

        return match ($contentType) {
            QuickAddContentType::Links => app(LibraryLinkService::class)->create($user, [
                'url' => $url,
                'title' => $metadata['title'] ?? null,
            ]),

            QuickAddContentType::Todo => app(TodoService::class)->create($user, $this->parseTodoData($user, $url, $metadata)),

            QuickAddContentType::Note => app(NoteService::class)->create($user, [
                'title' => $metadata['title'] ?? $url,
            ]),

            QuickAddContentType::Quotes => app(LibraryQuoteService::class)->create($user, [
                'quote'  => $metadata['quote'] ?? $url,
                'author' => $metadata['author'] ?? null,
                'source' => $metadata['source'] ?? null,
            ]),

            QuickAddContentType::Recipes => app(LibraryRecipeService::class)->create($user, [
                'title' => $metadata['title'] ?? $url,
                'link'  => $url,
            ]),

            QuickAddContentType::Plants => app(LibraryPlantService::class)->create($user, [
                'name' => $metadata['name'] ?? $url,
            ]),

            QuickAddContentType::Feed => app(FeedService::class)->createSubscription(
                $user,
                $metadata['title'] ?? $url,
                $url,
                null,
                null,
            ),

            QuickAddContentType::Clipboard => app(ClipboardService::class)->store($user, [
                'content' => $metadata['content'] ?? $url,
                'type' => 'text',
            ]),

            QuickAddContentType::Music => $this->commitMusic($user, $url),
            QuickAddContentType::Books => $this->commitBook($user, $url),
            QuickAddContentType::Movies => $this->commitMovie($user, $url),
            QuickAddContentType::Games => $this->commitGame($user, $url),
        };
    }

    private function parseTodoData(User $user, string $input, ?array $metadata): array
    {
        $title = $metadata['title'] ?? $input;
        $data = [];

        $tagMatches = [];
        if (preg_match_all('/#([\w-]+)/', $title, $matches)) {
            foreach ($matches[1] as $tagName) {
                $tag = Tag::forUser($user->id)->whereRaw('LOWER(name) = ?', [strtolower($tagName)])->first()
                    ?? Tag::create(['name' => $tagName, 'user_id' => $user->id]);
                $tagMatches[] = $tag->id;
                $title = trim(str_replace('#' . $tagName, '', $title));
            }
        }

        if (!empty($tagMatches)) {
            $data['tags'] = $tagMatches;
        }

        if (preg_match('/\/([\w-]+)/', $title, $match)) {
            $category = TodoCategory::forUser($user->id)
                ->whereRaw('LOWER(title) = ?', [strtolower($match[1])])
                ->first();
            if ($category) {
                $data['category_id'] = $category->id;
                $title = trim(str_replace('/' . $match[1], '', $title));
            }
        }

        if (preg_match('/due:([\w.-]+)/', $title, $match)) {
            $data['due_at'] = $match[1];
            $title = trim(preg_replace('/due:[\w.-]+/', '', $title));
        }

        if (preg_match('/repeat:(daily|weekly|monthly|yearly)/', $title, $match)) {
            if (isset($data['due_at'])) {
                $data['recurrence_frequency'] = $match[1];
            }
            $title = trim(preg_replace('/repeat:(daily|weekly|monthly|yearly)/', '', $title));
        }

        $data['title'] = $title;

        return $data;
    }

    private function commitMusic(User $user, string $url): mixed
    {
        $service = app(LibraryMusicService::class);

        $dto = Str::contains($url, 'discogs.com')
            ? $service->importFromDiscogs($url)
            : $service->importFromDeezer($url);

        if ($dto === null) {
            return null;
        }

        return $service->create($user, [
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
        $service = app(LibraryBookService::class);

        $dto = Str::contains($url, 'goodreads.com')
            ? $service->importFromGoodreads($url)
            : $service->importFromHardcover($url);

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

        return $service->create($user, $data);
    }

    private function commitMovie(User $user, string $url): mixed
    {
        $service = app(LibraryMovieService::class);

        $dto = $service->importFromImdb($url);

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

    private function commitGame(User $user, string $url): mixed
    {
        $service = app(LibraryGameService::class);

        $isBgg = Str::contains($url, 'boardgamegeek.com');
        $dto = $isBgg
            ? $service->importFromBgg($url)
            : $service->importFromSteam($url);

        if ($dto === null) {
            return null;
        }

        return $service->create($user, [
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
