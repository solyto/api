<?php

namespace App\Api\Users\Jobs;

use App\Api\Users\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

class DeleteOldDeviceNotifications implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    public function handle(NotificationService $notificationService): void
    {
        $notificationService->deleteOldDeviceNotifications();
    }
}
