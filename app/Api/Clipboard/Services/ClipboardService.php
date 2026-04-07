<?php

namespace App\Api\Clipboard\Services;

use App\Api\Clipboard\Models\Clipboard;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ClipboardService
{
    private const string CACHE_KEY = 'clipboard';
    private const int CACHE_TTL = 86400;

    public function __construct(
        private readonly ClipboardImageService $imageService,
        private readonly UserCacheService $cache
    ) {}

    public function list(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            function () use ($user) {
                return Clipboard::forUser($user->id)->orderBy('created_at', 'DESC')->get();
            }
        );
    }

    public function store(User $user, array $data): Clipboard
    {
        $data['user_id'] = $user->id;

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return Clipboard::create($data);
    }

    public function storeImage(User $user, UploadedFile $file): ?Clipboard
    {
        $filePath = $this->imageService->save($file, $user->id);

        if (!$filePath) {
            return null;
        }

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return Clipboard::create([
            'user_id' => $user->id,
            'type' => 'image',
            'file_path' => $filePath,
        ]);
    }

    public function getImagePath(int $userId, Clipboard $clipboard): ?string
    {
        if ($clipboard->type !== 'image' || !$clipboard->file_path) {
            return null;
        }

        return Storage::disk('user_data')->path($clipboard->file_path);
    }

    public function destroy(User $user, Clipboard $clipboard): void
    {
        if ($clipboard->type === 'image' && $clipboard->file_path) {
            $this->imageService->delete($clipboard->file_path);
        }

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        $clipboard->delete();
    }
}
