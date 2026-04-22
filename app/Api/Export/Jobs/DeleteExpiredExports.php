<?php

namespace App\Api\Export\Jobs;

use App\Shared\Models\ExportJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;

class DeleteExpiredExports implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    public function handle(): void
    {
        $jobs = ExportJob::where('status', 'completed')
            ->where('created_at', '<=', now()->subHours(48))
            ->get();

        foreach ($jobs as $job) {
            $relativePath = $job->user_id.'/export_'.$job->id.'.zip';

            if (Storage::disk('user_data')->exists($relativePath)) {
                Storage::disk('user_data')->delete($relativePath);
            }

            $job->delete();
        }
    }
}
