<?php

namespace App\Api\Feeds\Jobs;

use App\Api\Feeds\Models\FeedItem;
use App\Api\Feeds\Services\FeedService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class DeleteOldFeedItems implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    private const int BATCH_SIZE = 1000;

    public int $timeout = 300;

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
    public function handle(FeedService $feedService): void
    {
        $cutoff = Carbon::now()->subDays((int) config('feeds.retention_days'));

        $affectedFeedIds = $this->expired($cutoff)->distinct()->pluck('feed_id');

        $deleted = 0;

        while (true) {
            $ids = $this->expired($cutoff)->limit(self::BATCH_SIZE)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += FeedItem::whereIn('id', $ids)->delete();
        }

        if ($deleted === 0) {
            return;
        }

        foreach ($affectedFeedIds as $feedId) {
            $feedService->forgetFeedItemsCache($feedId);
        }

        Log::channel('queue')->info('Deleted expired feed items', [
            'items' => $deleted,
            'feeds' => $affectedFeedIds->count(),
        ]);
    }

    /**
     * Items predating the retention window. Rows with no publish date fall back to
     * their ingest time, otherwise a NULL published_at would never match and the
     * item would stay in the table forever.
     */
    private function expired(Carbon $cutoff): Builder
    {
        return FeedItem::where(function (Builder $query) use ($cutoff) {
            $query->where('published_at', '<', $cutoff)
                ->orWhere(function (Builder $query) use ($cutoff) {
                    $query->whereNull('published_at')->where('created_at', '<', $cutoff);
                });
        });
    }
}
