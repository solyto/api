<?php

namespace App\Api\Contacts\Jobs;

use App\Dav\Services\DavService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class ImportContactsFile implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    // Imported contacts are not de-duplicated, so a retry would store the first half twice; 300 s stays below the queue's retry_after of 360 s.
    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public string $userId,
        public string $jobId,
    ) {}

    public function handle(DavService $dav): void
    {
        $dav->import()->addressBooks()->importFile($this->userId, $this->jobId);
    }

    public function failed(?Throwable $exception): void
    {
        app(DavService::class)->import()->addressBooks()->fail($this->userId, $this->jobId);
    }
}
