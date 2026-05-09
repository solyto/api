<?php

namespace App\Api\Libraries\Services;

use App\Api\Libraries\Enums\LibraryTypeEnum;
use App\Api\Libraries\Jobs\GenerateCoverPreview;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LibraryCoverService
{
    public function loadCover(string $userId, LibraryTypeEnum $type, string $fileName): string | false
    {
        if (!Storage::disk('user_data')->exists($userId . '/' . $type->value . '/' . $fileName)) {
            return false;
        }

        return Storage::disk('user_data')->get($userId . '/' . $type->value . '/' . $fileName);
    }

     public function saveCover(string $userId, string $url, LibraryTypeEnum $type): string | false
     {
        $content = $this->getContents($url);

        if (!$content) {
            return false;
        }

        if (!$this->createDir($userId, $type)) {
            return false;
        }

         $extension = $this->getFileExtension($url);
         $store = $this->storeFile($userId, $type->value, $content, $extension);

         if (!$store) {
             return false;
         }

         GenerateCoverPreview::dispatch('user_data', $store);

         return Str::replace($userId . '/' . $type->value . '/', '', $store);
     }

    public function uploadCover(string $userId, UploadedFile $file, LibraryTypeEnum $type): string | false
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file->getRealPath());

        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime, $allowed, true)) {
            return false;
        }

        if (!$this->createDir($userId, $type)) {
            return false;
        }

        $content = file_get_contents($file->getRealPath());
        $extension = $this->extensionFromMime($mime);
        $store = $this->storeFile($userId, $type->value, $content, $extension);

        if (!$store) {
            return false;
        }

        GenerateCoverPreview::dispatch('user_data', $store);

        return Str::replace($userId . '/' . $type->value . '/', '', $store);
    }

    public function deleteCover(string $userId, LibraryTypeEnum $type, string $filename): void
    {
        $path = $userId . '/' . $type->value . '/' . $filename;
        if (Storage::disk('user_data')->exists($path)) {
            Storage::disk('user_data')->delete($path);
        }
    }

    private function getContents(string $url): string | false
    {
        $response = Http::get($url);

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        if (!str_starts_with($response->getHeaderLine('Content-Type'), 'image/')) {
            return false;
        }

        $content = $response->getBody()->getContents();
        if (empty($content)) {
            return false;
        }

        return $content;
    }

    private function createDir(string $userId, LibraryTypeEnum $type): bool
    {
        if (!Storage::disk('user_data')->exists($userId)) {
            if (!Storage::disk('user_data')->makeDirectory($userId)) {
                return false;
            }
        }

        if (!Storage::disk('user_data')->exists($userId . '/' . $type->value)) {
            if (!Storage::disk('user_data')->makeDirectory($userId . '/' . $type->value)) {
                return false;
            }
        }

        return true;
    }

    private function getFileExtension(string $url): string
    {
        $path      = parse_url($url, PHP_URL_PATH);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        return $extension ? : 'jpg';
    }

    private function extensionFromMime(string $mime): string
    {
        return match ($mime) {
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            default      => 'jpg',
        };
    }

    private function storeFile(string $userId, string $type, string $content, string $extension): string | false
    {
        $filename = uniqid() . '.' . $extension;
        $path     = $userId . '/' . $type . '/' . $filename;

        return Storage::disk('user_data')->put($path, $content) ? $path : false;
    }
}
