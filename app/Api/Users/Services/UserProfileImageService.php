<?php

namespace App\Api\Users\Services;

use App\Api\Users\Jobs\ScaleProfileImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class UserProfileImageService
{
    public function load(string $userId, string $fileName): string | false
    {
        if (!Storage::disk('user_data')->exists($userId . '/' . $fileName)) {
            return false;
        }

        return Storage::disk('user_data')->get($userId . '/' . $fileName);
    }

    public function save(string $userId, UploadedFile $file): string | false
    {
        if (!$this->createDir($userId)) {
            return false;
        }

        if (!$this->validateImage($file)) {
            return false;
        }

        $sanitizedName = $this->sanitizeFileName($userId, $file);
        $store = Storage::disk('user_data')->putFileAs($userId, $file, $sanitizedName);

        if (!$store) {
            return false;
        }

        $path = $userId . '/' . $sanitizedName;
        ScaleProfileImage::dispatch($path);

        return $path;
    }

    private function createDir(string $userId): bool
    {
        if (!Storage::disk('user_data')->exists($userId)) {
            if (!Storage::disk('user_data')->makeDirectory($userId)) {
                return false;
            }
        }

        return true;
    }

    private function validateImage(UploadedFile $file): bool
    {
        $validator = Validator::make(['image' => $file], [
            'image' => 'required|image|mimes:jpeg,png,gif,webp|max:2048|dimensions:min=50,max=4096'
        ]);

        if ($validator->fails()) {
            return false;
        }

        return true;
    }

    private function sanitizeFileName(string $userId, UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        return 'profile_image_' . bin2hex(random_bytes(8)) . '.' . $extension;
    }
}
