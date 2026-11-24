<?php

namespace App\Api\Calendars\Jobs;

use App\Dav\Services\DavService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class ImportCalendarFile implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    // Imported events are not de-duplicated, so a retry would store the first half twice; 300 s stays below the queue's retry_after of 360 s.
    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public string $userId,
        public string $jobId,
    ) {}

    public function handle(DavService $dav): void
    {
        $dav->import()->calendars()->importFile($this->userId, $this->jobId);
    }

    public function failed(?Throwable $exception): void
    {
        app(DavService::class)->import()->calendars()->fail($this->userId, $this->jobId);
    }
}
