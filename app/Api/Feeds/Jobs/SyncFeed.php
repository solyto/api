<?php

namespace App\Api\Feeds\Jobs;

use App\Api\Feeds\Services\FeedService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

class SyncFeed implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    /**
     * Create a new job instance.
     */
    public function __construct(private string $feedId)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $feedService = app(FeedService::class);
        $feedService->syncFeed($this->feedId);
    }
}
