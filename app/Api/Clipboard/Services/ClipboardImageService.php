<?php

namespace App\Api\Clipboard\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ClipboardImageService
{
    private const string DISK = 'private_user_data';

    public function save(UploadedFile $file, string $userId): string|false
    {
        $directory = $userId . '/clipboard';

        if (!Storage::disk(self::DISK)->exists($directory)) {
            if (!Storage::disk(self::DISK)->makeDirectory($directory)) {
                return false;
            }
        }

        if (!$this->validateImage($file)) {
            return false;
        }

        $mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $extension = $mimeToExt[$file->getMimeType()] ?? 'png';
        $sanitizedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $store = Storage::disk(self::DISK)->putFileAs($directory, $file, $sanitizedName);

        return $store ? $directory . '/' . $sanitizedName : false;
    }

    private function validateImage(UploadedFile $file): bool
    {
        $validator = Validator::make(['image' => $file], [
            'image' => 'required|image|mimes:jpeg,png,gif,webp|max:5120|dimensions:max_width=4096,max_height=4096'
        ]);

        return !$validator->fails();
    }
}
