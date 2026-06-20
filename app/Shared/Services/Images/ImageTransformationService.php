<?php

namespace App\Shared\Services\Images;

use Illuminate\Support\Facades\File;

class ImageTransformationService
{
    public function __construct(private readonly ImageDriverInterface $driver) {}

    public function generatePreview(string $disk, string $path): string|false
    {
        return $this->driver->generatePreview($disk, $path);
    }

    public function scaleToWidth(string $absolutePath, int $width, int $quality): bool
    {
        return $this->driver->scaleToWidth($absolutePath, $width, $quality);
    }

    public function scaleToFileSize(string $absolutePath, int $maxBytes): bool
    {
        return $this->driver->scaleToFileSize($absolutePath, $maxBytes);
    }

    public function base64ToTemp(string $base64): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'img_');
        File::put($tmpPath, base64_decode($base64, true));
        return $tmpPath;
    }

    public function tempToBase64(string $tempPath): string
    {
        return base64_encode(File::get($tempPath));
    }
}
