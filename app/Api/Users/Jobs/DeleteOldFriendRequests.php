<?php

namespace App\Api\Users\Jobs;

use App\Api\Users\Models\FriendRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

class DeleteOldFriendRequests implements ShouldQueue
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
        FriendRequest::where(function ($query) {
            $query->where('status', 'accepted')->orWhere('status', 'rejected');
        })->where('created_at', '<', now()->subDays(7))->delete();
    }
}
