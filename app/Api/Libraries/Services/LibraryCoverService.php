<?php

namespace App\Api\Libraries\Services;

use App\Api\Libraries\Enums\LibraryTypeEnum;
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

         return $store ?
             Str::replace($userId . '/' . $type->value . '/', '', $store) :
             false;
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

    private function storeFile(string $userId, string $type, string $content, string $extension): string | false
    {
        $filename = uniqid() . '.' . $extension;
        $path     = $userId . '/' . $type . '/' . $filename;

        return Storage::disk('user_data')->put($path, $content) ? $path : false;
    }
}
