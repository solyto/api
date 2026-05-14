<?php

namespace App\Shared\Services\Images;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Imagick\Driver;
use Intervention\Image\ImageManager;

class InterventionDriver implements ImageDriverInterface
{
    private const int PREVIEW_QUALITY = 1;

    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver());
    }

    public function generatePreview(string $disk, string $path): string|false
    {
        $absolutePath        = Storage::disk($disk)->path($path);
        $previewPath         = $this->previewPath($path);
        $previewAbsolutePath = Storage::disk($disk)->path($previewPath);

        try {
            $image = $this->manager->read($absolutePath);
            $image->save($previewAbsolutePath, quality: self::PREVIEW_QUALITY);
        } catch (\Exception) {
            return false;
        }

        return $previewPath;
    }

    public function scaleToWidth(string $absolutePath, int $width, int $quality): bool
    {
        try {
            $image = $this->manager->read($absolutePath);
            $image->scale(width: $width);
            $image->save($absolutePath, quality: $quality);
        } catch (\Exception) {
            return false;
        }

        return true;
    }

    private function previewPath(string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $base      = $extension ? substr($path, 0, -strlen($extension) - 1) : $path;

        return $base . '_preview' . ($extension ? '.' . $extension : '');
    }
}
