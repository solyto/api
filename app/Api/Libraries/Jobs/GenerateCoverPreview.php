<?php

namespace App\Api\Libraries\Jobs;

use App\Shared\Services\ImageTransformationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

class GenerateCoverPreview implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    public function __construct(private string $disk, private string $path) {}

    public function handle(ImageTransformationService $imageTransformation): void
    {
        $imageTransformation->generatePreview($this->disk, $this->path);
    }
}
