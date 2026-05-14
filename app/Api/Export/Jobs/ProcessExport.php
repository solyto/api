<?php

namespace App\Api\Export\Jobs;

use App\Api\Export\Services\ExportService;
use App\Api\Users\Models\User;
use App\Dav\Services\DavService;
use App\Shared\Models\ExportJob;
use App\Shared\Notifications\ExportReadyNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;

class ProcessExport implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    public int $timeout = 300;

    public function __construct(
        private readonly string $userId,
        private readonly int $jobId,
    ) {}

    public function handle(): void
    {
        $user = User::find($this->userId);
        $job = ExportJob::find($this->jobId);

        if (!$user || !$job) {
            return;
        }

        $job->update(['status' => 'in_progress']);

        try {
            $service = new ExportService($user, $job, app(DavService::class));
            $service->export();

            $job->update(['status' => 'completed']);

            $user->notify(new ExportReadyNotification((string) $job->id, true));
        } catch (\Throwable $e) {
            $job->update(['status' => 'failed']);

            $relativePath = $user->id.'/export_'.$job->id.'.zip';
            if (Storage::disk('user_data')->exists($relativePath)) {
                Storage::disk('user_data')->delete($relativePath);
            }

            $user->notify(new ExportReadyNotification((string) $job->id, false));

            throw $e;
        }
    }
}
