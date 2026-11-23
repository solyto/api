<?php

namespace App\Api\DevRequests\Jobs;

use App\Api\DevRequests\Models\DevRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

class DeleteOldDevRequests implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    private const int KEEP_CLOSED_DAYS = 30;

    public function handle(): void
    {
        DevRequest::where('created_at', '<', now()->subDays(self::KEEP_CLOSED_DAYS))
            ->whereIn('status', ['cancelled', 'completed'])
            ->delete();
    }
}
