<?php

namespace App\Api\Feeds\Jobs;

use App\Api\Feeds\Models\Feed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SyncFeeds implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Dispatch one job per feed so that a single slow or failing feed cannot
     * starve the others. This job only enqueues work, it never fetches.
     */
    public function handle(): void
    {
        $dispatched = 0;

        Feed::has('subscriptions')->chunkById(100, function ($feeds) use (&$dispatched) {
            foreach ($feeds as $feed) {
                SyncFeed::dispatch($feed->id);
                $dispatched++;
            }
        });

        Log::channel('queue')->info('Dispatched feed sync jobs', ['feeds' => $dispatched]);
    }
}
