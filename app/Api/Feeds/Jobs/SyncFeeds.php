<?php

namespace App\Api\Feeds\Jobs;

use App\Api\Feeds\Models\Feed;
use App\Api\Feeds\Services\FeedService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SyncFeeds implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $feedService = app(FeedService::class);

        Feed::has('subscriptions')->chunk(100, function ($feeds) use ($feedService) {
            foreach ($feeds as $feed) {
                Log::channel('queue')->info('Syncing items for feed ' . $feed->title);
                $feedService->syncFeed($feed->id);
            }
        });
    }
}
