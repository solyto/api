<?php

namespace App\Api\Feeds\Jobs;

use App\Api\Feeds\Services\FeedService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class SyncFeed implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    private const int LOCK_EXPIRY = 300;

    public int $tries = 2;

    public int $timeout = 240;

    /**
     * Create a new job instance.
     */
    public function __construct(private string $feedId)
    {
        //
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->feedId))
                ->dontRelease()
                ->expireAfter(self::LOCK_EXPIRY),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(FeedService $feedService): void
    {
        try {
            if (!$feedService->syncFeed($this->feedId)) {
                Log::channel('queue')->warning('Feed sync produced no items', ['feed_id' => $this->feedId]);
            }
        } catch (\Throwable $e) {
            $feedService->recordSyncFailure($this->feedId, $e->getMessage());

            Log::channel('queue')->error('Feed sync failed', [
                'feed_id' => $this->feedId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
