<?php

namespace App\Api\Clipboard\Jobs;

use App\Api\Clipboard\Services\ClipboardService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

class DeleteOverdueClipboardEntries implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    public function __construct()
    {
        //
    }

    public function handle(ClipboardService $clipboardService): void
    {
        $clipboardService->deleteOverdue();
    }
}
