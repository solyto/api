<?php

namespace App\Api\Feeds\Jobs;

use App\Api\Feeds\Services\OpmlImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class ImportFeedSubscriptions implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    // A retry would redo a half-finished import; 300 s stays below the queue's retry_after of 360 s.
    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(private string $userId) {}

    public function handle(OpmlImportService $importService): void
    {
        $importService->run($this->userId);
    }

    public function failed(?Throwable $exception): void
    {
        app(OpmlImportService::class)->fail($this->userId);
    }
}
