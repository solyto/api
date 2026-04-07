<?php

namespace App\Api\Feeds\Jobs;

use App\Api\Feeds\Models\FeedItem;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DeleteOldFeedItems implements ShouldQueue
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
        $items = FeedItem::where('published_at', '<', Carbon::now()->subDays(5)->format('Y-m-d H:i:s'))->get();
        $deletedItems = [];

        foreach ($items as $item) {
            Log::channel('queue')->info('Deleting item: ' . $item->title);
            $deletedItems[] = $item->id;
            $item->delete();
        }

        if (count($deletedItems) > 0) {
            Cache::store('user_data')->tags(['feed_items'])->flush();
        }
    }
}
