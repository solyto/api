<?php

namespace App\Api\Libraries\Services;

use App\Api\Libraries\DTOs\BookReleaseDTO;
use App\Api\Libraries\Enums\LibraryRecommendationEnum;
use App\Api\Libraries\Enums\LibraryTypeEnum;
use App\Api\Libraries\Enums\BookServiceEnum;
use App\Api\Libraries\Models\LibraryBook;
use App\Api\Libraries\Models\LibraryBookGenre;
use App\Api\Libraries\Services\External\GoodreadsService;
use App\Api\Libraries\Services\External\HardcoverService;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Collection;

class LibraryBookService
{
    private const string CACHE_KEY = 'books';
    private const string CACHE_KEY_RELEASES        = 'book_releases';
    private const string CACHE_KEY_RECOMMENDATIONS = 'book_recommendations';
    private const int CACHE_TTL                    = 86400;
    private const int CACHE_TTL_RELEASES           = 86400;

    public function __construct(
        private readonly LibraryCoverService $coverService,
        private readonly HardcoverService $hardcoverService,
        private readonly GoodreadsService $goodreadsService,
        private readonly UserCacheService $cache,
    ) {}

    public function list(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            fn() => LibraryBook::forUser($user->id)->with(['genres', 'tags'])->orderBy('author', 'asc')->get()
        );
    }

    public function find(LibraryBook $book): LibraryBook
    {
        $book->load(['genres', 'tags']);

        return $book;
    }

    public function create(User $user, array $data): LibraryBook
    {
        $data['user_id'] = $user->id;

        if (!empty($data['cover_path'])) {
            $save = $this->coverService->saveCover($data['user_id'], $data['cover_path'], LibraryTypeEnum::BOOK);
            if ($save) {
                $data['cover_path'] = $save;
            }
        }

        $book = LibraryBook::create($data);

        if (isset($data['genres'])) {
            $book->genres()->attach($data['genres']);
        }

        if (isset($data['tags'])) {
            $book->tags()->attach($data['tags']);
        }

        $book->load(['user', 'genres', 'tags']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $book;
    }

    public function update(LibraryBook $book, array $data): LibraryBook
    {
        if (!empty($data['cover_path'])) {
            $save = $this->coverService->saveCover($book->user_id, $data['cover_path'], LibraryTypeEnum::BOOK);
            if ($save) {
                $data['cover_path'] = $save;
            }
        }

        $book->update($data);

        if (array_key_exists('genres', $data)) {
            $book->genres()->sync($data['genres']);
        }

        if (array_key_exists('tags', $data)) {
            $book->tags()->sync($data['tags']);
        }

        $book->load(['user', 'genres', 'tags']);

        $this->cache->forget([self::CACHE_KEY, $book->user_id]);

        return $book;
    }

    public function destroy(LibraryBook $book): void
    {
        $userId = $book->user_id;
        $book->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }

    public function recommend(User $user, LibraryRecommendationEnum $type): ?array
    {
        $recommender = new LibraryRecommender(LibraryTypeEnum::BOOK, $user);

        if ($type === LibraryRecommendationEnum::NEW) {
            return $recommender->new();
        }

        return call_user_func([$recommender, $type->value]);
    }

    public function recommendNew(User $user): mixed
    {
        $stack = $this->cache->get([self::CACHE_KEY_RECOMMENDATIONS, $user->id]) ?? [];

        if (empty($stack)) {
            $result = $this->recommend($user, LibraryRecommendationEnum::NEW);
            $stack = $result['recommendations'] ?? [];
        }

        $item = array_shift($stack);

        $this->cache->store([self::CACHE_KEY_RECOMMENDATIONS, $user->id], self::CACHE_TTL, $stack);

        return $item;
    }

    public function releases(User $user): array
    {
        return $this->cache->remember(
            [self::CACHE_KEY_RELEASES, $user->id],
            self::CACHE_TTL_RELEASES,
            function () use ($user) {
                $service = app()->makeWith(LibraryReleases::class, ['user' => $user]);
                return $service->getBookReleases();
            }
        );
    }

    public function search(BookServiceEnum $service, string $query): ?array
    {
        return match ($service) {
            BookServiceEnum::HARDCOVER => $this->hardcoverService->searchBooks($query),
            BookServiceEnum::GOODREADS => [],
        };
    }

    public function import(BookServiceEnum $service, string $url): ?BookReleaseDTO
    {
        return match ($service) {
            BookServiceEnum::HARDCOVER  => $this->hardcoverService->importFromUrl($url),
            BookServiceEnum::GOODREADS  => $this->goodreadsService->importFromUrl($url),
        };
    }

    public function listGenres(User $user): Collection
    {
        return LibraryBookGenre::forUser($user->id)->get();
    }

    public function findGenre(LibraryBookGenre $genre): LibraryBookGenre
    {
        return $genre;
    }

    public function createGenre(User $user, array $data): LibraryBookGenre
    {
        $data['user_id'] = $user->id;
        $genre = LibraryBookGenre::create($data);
        $genre->load(['user']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $genre;
    }

    public function updateGenre(LibraryBookGenre $genre, array $data): LibraryBookGenre
    {
        $userId = $genre->user_id;
        $genre->update($data);
        $genre->load(['user']);

        $this->cache->forget([self::CACHE_KEY, $userId]);

        return $genre;
    }

    public function destroyGenre(LibraryBookGenre $genre): void
    {
        $userId = $genre->user_id;
        $genre->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }
}
