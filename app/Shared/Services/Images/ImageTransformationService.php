<?php

namespace App\Shared\Services\Images;

use App\Shared\Services\Images\ImageDriverInterface;

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
}
