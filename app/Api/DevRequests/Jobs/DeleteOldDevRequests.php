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
        DevRequest::where('created_at', '<', now()->subDays(30))->whereIn('status', ['backlog', 'pending', 'in-progress'])->delete();
    }
}
