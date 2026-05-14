<?php

namespace App\Api\Users\Jobs;

use App\Shared\Services\Images\ImageTransformationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;

class ScaleProfileImage implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    public function __construct(private readonly string $path) {}

    public function handle(ImageTransformationService $imageTransformation): void
    {
        $absolutePath = Storage::disk('user_data')->path($this->path);
        $imageTransformation->scaleToWidth($absolutePath, 400, 85);
    }
}
