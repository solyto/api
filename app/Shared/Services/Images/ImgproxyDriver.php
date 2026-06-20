<?php

namespace App\Shared\Services\Images;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ImgproxyDriver implements ImageDriverInterface
{
    private const int PREVIEW_QUALITY = 1;

    public function generatePreview(string $disk, string $path): string|false
    {
        $absolutePath = Storage::disk($disk)->path($path);
        $localPath    = ltrim(str_replace(storage_path(), '', $absolutePath), '/');

        $imgproxyPath = '/q:' . self::PREVIEW_QUALITY . '/plain/local:///' . $localPath;
        $signedUrl    = $this->buildSignedUrl($imgproxyPath);

        try {
            $response = Http::get($signedUrl);
        } catch (ConnectionException) {
            return false;
        }

        if ($response->failed()) {
            return false;
        }

        $previewPath = $this->previewPath($path);
        $stored      = Storage::disk($disk)->put($previewPath, $response->body());

        return $stored ? $previewPath : false;
    }

    public function scaleToFileSize(string $absolutePath, int $maxBytes): bool
    {
        $quality = 82;
        $width   = 800;

        while ($width >= 100) {
            if (!$this->scaleToWidth($absolutePath, $width, $quality)) {
                return false;
            }

            clearstatcache(true, $absolutePath);
            $size = filesize($absolutePath);
            if ($size <= $maxBytes) {
                return true;
            }

            // Estimate next width using area-ratio heuristic, but always reduce by at least 20%
            $estimated = (int) ($width * sqrt($maxBytes / $size));
            $width     = min($estimated, (int) ($width * 0.8));
            $width     = max($width, 100);
        }

        clearstatcache(true, $absolutePath);
        return filesize($absolutePath) <= $maxBytes;
    }

    public function scaleToWidth(string $absolutePath, int $width, int $quality): bool
    {
        $localPath = ltrim(str_replace(storage_path(), '', $absolutePath), '/');

        $imgproxyPath = '/rs:fit:' . $width . ':0/q:' . $quality . '/plain/local:///' . $localPath;
        $signedUrl    = $this->buildSignedUrl($imgproxyPath);

        try {
            $response = Http::get($signedUrl);
        } catch (ConnectionException) {
            return false;
        }

        if ($response->failed()) {
            return false;
        }

        file_put_contents($absolutePath, $response->body());

        return true;
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
