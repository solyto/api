<?php

namespace App\Shared\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ImageTransformationService
{
    private const int QUALITY = 1;

    public function generatePreview(string $disk, string $path): string|false
    {
        $absolutePath = Storage::disk($disk)->path($path);
        $localPath = ltrim(str_replace(storage_path(), '', $absolutePath), '/');

        $imgproxyPath = '/q:' . self::QUALITY . '/plain/local:///' . $localPath;
        $signedUrl = $this->buildSignedUrl($imgproxyPath);

        try {
            $response = Http::get($signedUrl);
        } catch (ConnectionException $e) {
            return false;
        }

        if ($response->failed()) {
            return false;
        }

        $previewPath = $this->previewPath($path);
        $stored = Storage::disk($disk)->put($previewPath, $response->body());

        return $stored ? $previewPath : false;
    }

    private function buildSignedUrl(string $path): string
    {
        $key  = hex2bin(config('services.imgproxy.key'));
        $salt = hex2bin(config('services.imgproxy.salt'));

        $signature = rtrim(
            strtr(base64_encode(hash_hmac('sha256', $salt . $path, $key, true)), '+/', '-_'),
            '='
        );

        return config('services.imgproxy.url') . '/' . $signature . $path;
    }

    private function previewPath(string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $base      = $extension ? substr($path, 0, -strlen($extension) - 1) : $path;

        return $base . '_preview' . ($extension ? '.' . $extension : '');
    }
}
