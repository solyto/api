<?php

namespace App\Api\Libraries\Services;

use App\Api\Libraries\Enums\LibraryTypeEnum;
use App\Api\Libraries\Models\LibraryVideo;
use App\Api\Libraries\Models\LibraryVideoCategory;
use App\Api\Users\Models\User;
use App\Shared\Services\UrlCrawlerService;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Collection;

class LibraryVideoService
{
    private const string CACHE_KEY = 'videos';
    private const int CACHE_TTL = 86400;

    public function __construct(
        private readonly LibraryCoverService $coverService,
        private readonly UserCacheService $cache,
        private readonly UrlCrawlerService $urlCrawlerService,
    ) {}

    public function list(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            fn() => LibraryVideo::forUser($user->id)
                ->with('category')
                ->orderBy('sort_order', 'asc')
                ->orderBy('created_at', 'desc')
                ->get()
        );
    }

    public function find(LibraryVideo $video): LibraryVideo
    {
        $video->load('category');

        return $video;
    }

    public function create(User $user, array $data): LibraryVideo
    {
        $data['user_id'] = $user->id;

        if (empty($data['title'])) {
            $data['title'] = $this->urlCrawlerService->fetchTitle($data['url']);
        }

        $data['video_id'] = $this->extractVideoId($data['url']);

        $thumbnailUrl = $data['cover_path'] ?? ($data['video_id'] ? $this->thumbnailUrl($data['video_id']) : null);
        $data['cover_path'] = null;

        if ($thumbnailUrl) {
            $save = $this->coverService->saveCover($data['user_id'], $thumbnailUrl, LibraryTypeEnum::VIDEO);
            if ($save) {
                $data['cover_path'] = $save;
            }
        }

        $video = LibraryVideo::create($data);
        $video->load(['user', 'category']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $video;
    }

    public function update(LibraryVideo $video, array $data): LibraryVideo
    {
        if (!empty($data['url']) && $data['url'] !== $video->url) {
            $data['video_id'] = $this->extractVideoId($data['url']) ?? $video->video_id;
        }

        if (!empty($data['cover_path'])) {
            $save = $this->coverService->saveCover($video->user_id, $data['cover_path'], LibraryTypeEnum::VIDEO);
            if ($save) {
                $data['cover_path'] = $save;
            }
        }

        $video->update($data);
        $video->load(['user', 'category']);

        $this->cache->forget([self::CACHE_KEY, $video->user_id]);

        return $video;
    }

    public function destroy(LibraryVideo $video): void
    {
        $userId = $video->user_id;
        $video->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }

    public function reorder(User $user, array $ids): void
    {
        foreach ($ids as $order => $id) {
            LibraryVideo::where('id', $id)->where('user_id', $user->id)->update(['sort_order' => $order]);
        }

        $this->cache->forget([self::CACHE_KEY, $user->id]);
    }

    public function listCategories(User $user): Collection
    {
        return LibraryVideoCategory::forUser($user->id)->orderBy('sort_order', 'asc')->orderBy('title', 'asc')->get();
    }

    public function createCategory(User $user, array $data): LibraryVideoCategory
    {
        $data['user_id'] = $user->id;
        $category = LibraryVideoCategory::create($data);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $category;
    }

    public function updateCategory(LibraryVideoCategory $category, array $data): LibraryVideoCategory
    {
        $userId = $category->user_id;
        $category->update($data);

        $this->cache->forget([self::CACHE_KEY, $userId]);

        return $category;
    }

    public function destroyCategory(LibraryVideoCategory $category): void
    {
        $userId = $category->user_id;
        $category->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }

    public function reorderCategories(User $user, array $ids): void
    {
        foreach ($ids as $order => $id) {
            LibraryVideoCategory::where('id', $id)->where('user_id', $user->id)->update(['sort_order' => $order]);
        }

        $this->cache->forget([self::CACHE_KEY, $user->id]);
    }

    private function extractVideoId(string $url): ?string
    {
        if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function thumbnailUrl(string $videoId): string
    {
        return "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg";
    }
}
