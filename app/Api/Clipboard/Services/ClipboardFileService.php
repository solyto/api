<?php

namespace App\Api\Clipboard\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ClipboardFileService
{
    private const string DISK = 'private_user_data';

    public function save(UploadedFile $file, string $userId, string $extension): string|false
    {
        $directory = $userId . '/clipboard';
        $name = bin2hex(random_bytes(16)) . '.' . $extension;

        return Storage::disk(self::DISK)->putFileAs($directory, $file, $name);
    }

    public function path(string $filePath): string
    {
        return Storage::disk(self::DISK)->path($filePath);
    }

    public function exists(string $filePath): bool
    {
        return Storage::disk(self::DISK)->exists($filePath);
    }

    public function delete(string $filePath): void
    {
        Storage::disk(self::DISK)->delete($filePath);
    }
}
