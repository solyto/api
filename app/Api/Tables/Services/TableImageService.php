<?php

namespace App\Api\Tables\Services;

use App\Api\Tables\Models\Table;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class TableImageService
{
    private const string DISK = 'user_data';

    public function load(string $userId, Table $table, string $fileName): string|false
    {
        $path = $this->path($userId, $table, $fileName);

        if (! Storage::disk(self::DISK)->exists($path)) {
            return false;
        }

        return Storage::disk(self::DISK)->get($path);
    }

    public function upload(string $userId, Table $table, UploadedFile $file): string|false
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file->getRealPath());

        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        if (! array_key_exists($mime, $allowed)) {
            return false;
        }

        $directory = $userId.'/tables/'.$table->id;

        if (! Storage::disk(self::DISK)->exists($directory)) {
            Storage::disk(self::DISK)->makeDirectory($directory);
        }

        $fileName = uniqid().'.'.$allowed[$mime];
        $stored = Storage::disk(self::DISK)->put($directory.'/'.$fileName, file_get_contents($file->getRealPath()));

        return $stored ? $fileName : false;
    }

    public function delete(string $userId, Table $table, string $fileName): void
    {
        $path = $this->path($userId, $table, $fileName);

        if (Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    private function path(string $userId, Table $table, string $fileName): string
    {
        return $userId.'/tables/'.$table->id.'/'.$fileName;
    }
}
