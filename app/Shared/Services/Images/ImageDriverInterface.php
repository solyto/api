<?php

namespace App\Shared\Services\Images;

interface ImageDriverInterface
{
    public function generatePreview(string $disk, string $path): string|false;
    public function scaleToWidth(string $absolutePath, int $width, int $quality): bool;
}
