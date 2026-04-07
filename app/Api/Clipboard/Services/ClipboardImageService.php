<?php

namespace App\Api\Clipboard\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ClipboardImageService
{
    public function save(UploadedFile $file, string $userId): string|false
    {
        $directory = $userId . '/clipboard';

        if (!Storage::disk('user_data')->exists($directory)) {
            if (!Storage::disk('user_data')->makeDirectory($directory)) {
                return false;
            }
        }

        if (!$this->validateImage($file)) {
            return false;
        }

        $mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $extension = $mimeToExt[$file->getMimeType()] ?? 'png';
        $sanitizedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $store = Storage::disk('user_data')->putFileAs($directory, $file, $sanitizedName);

        return $store ? $directory . '/' . $sanitizedName : false;
    }

    public function delete(string $filePath): void
    {
        if (Storage::disk('user_data')->exists($filePath)) {
            Storage::disk('user_data')->delete($filePath);
        }
    }

    private function validateImage(UploadedFile $file): bool
    {
        $validator = Validator::make(['image' => $file], [
            'image' => 'required|image|mimes:jpeg,png,gif,webp|max:5120|dimensions:max_width=4096,max_height=4096'
        ]);

        return !$validator->fails();
    }
}
