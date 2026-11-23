<?php

namespace App\Api\Clipboard\Services;

use App\Api\Clipboard\Models\Clipboard;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ClipboardService
{
    private const string CACHE_KEY = 'clipboard';
    private const int CACHE_TTL = 86400;
    private const int CHUNK = 100;

    public function __construct(
        private readonly ClipboardImageService $imageService,
        private readonly ClipboardFileService $fileService,
        private readonly UserCacheService $cache
    ) {}

    public function list(User $user, bool $includeFiles = false): Collection
    {
        $entries = $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            function () use ($user) {
                return Clipboard::forUser($user->id)->orderBy('created_at', 'DESC')->get();
            }
        );

        return $includeFiles ? $entries : $entries->where('type', '!=', 'file')->values();
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

    /** @throws HttpException 413 when the file exceeds clipboard.max_file_kb */
    public function storeFile(User $user, UploadedFile $file): Clipboard
    {
        if ($file->getSize() > config('clipboard.max_file_kb') * 1024) {
            throw new HttpException(413, 'The file is too large.');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $filePath = $this->fileService->save($file, $user->id, $extension);

        if (!$filePath) {
            throw new \RuntimeException('Failed to store clipboard file.');
        }

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return Clipboard::create([
            'user_id' => $user->id,
            'content' => '',
            'type' => 'file',
            'file_path' => $filePath,
            'file_name' => $this->displayName($file->getClientOriginalName(), $extension),
            'file_size' => $file->getSize(),
            'mime_type' => config('clipboard.file_types')[$extension][0],
        ]);
    }

    public function getFilePath(Clipboard $clipboard): ?string
    {
        if ($clipboard->type !== 'file' || !$clipboard->file_path || !$this->fileService->exists($clipboard->file_path)) {
            return null;
        }

        return $this->fileService->path($clipboard->file_path);
    }

    public function getImagePath(string $userId, Clipboard $clipboard): ?string
    {
        if ($clipboard->type !== 'image' || !$clipboard->file_path || !$this->fileService->exists($clipboard->file_path)) {
            return null;
        }

        return $this->fileService->path($clipboard->file_path);
    }

    public function destroy(User $user, Clipboard $clipboard): void
    {
        if ($clipboard->file_path) {
            $this->fileService->delete($clipboard->file_path);
        }

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        $clipboard->delete();
    }

    // Rows are deleted by the ids whose files were just removed, so an entry crossing the cutoff mid-run keeps its file.
    public function deleteOverdue(): void
    {
        $userIds = [];

        Clipboard::where('created_at', '<', now()->subDay())
            ->select(['id', 'user_id', 'file_path'])
            ->chunkById(self::CHUNK, function (Collection $entries) use (&$userIds) {
                foreach ($entries as $entry) {
                    if ($entry->file_path) {
                        $this->fileService->delete($entry->file_path);
                    }

                    $userIds[$entry->user_id] = true;
                }

                Clipboard::whereKey($entries->pluck('id'))->delete();
            });

        foreach (array_keys($userIds) as $userId) {
            $this->cache->forget([self::CACHE_KEY, $userId]);
        }
    }

    // The name is only shown and sent back in Content-Disposition; control characters, quotes and path separators go.
    private function displayName(string $original, string $extension): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\"]+/u', '', $original) ?? '');

        return $name !== '' ? mb_substr($name, -200) : 'file.' . $extension;
    }
}
